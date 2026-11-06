<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\Expr;
use Light\Linq\Expressions\Expression;
use Light\Linq\Expressions\MethodCallExpression;
use Light\Linq\Expressions\SourceExpression;
use Light\Linq\Expressions\ValueExpression;
use Light\Linq\InMemoryQueryProvider;
use Light\Linq\IQueryProvider;
use Light\Linq\Queryable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryProviderBoundaryTest extends TestCase
{
    public function testUnknownSourceIsRejectedWhenExecuted(): void
    {
        $query = Queryable::from(new InMemoryQueryProvider([]), 'missing');
        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage('Unknown query source: missing');
        $query->toArray();
    }

    public function testProviderRejectsValueExpressionAsQueryRoot(): void
    {
        $this->expectException(\LogicException::class);
        (new InMemoryQueryProvider([]))->execute(Expr::value(1));
    }

    public function testProviderRejectsUnknownQueryOperation(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unsupported query method: missing');
        (new InMemoryQueryProvider(['rows' => []]))->execute(new MethodCallExpression(new SourceExpression('rows'), 'missing'));
    }

    public function testProviderRejectsThenByWithoutPrimaryOrderEvenForExternallyBuiltExpressions(): void
    {
        $provider = new InMemoryQueryProvider(['rows' => []]);
        $expression = new MethodCallExpression(new SourceExpression('rows'), 'thenBy', [
            'keySelector' => Expr::field('id'), 'descending' => false,
        ]);
        $this->expectException(\LogicException::class);
        $provider->execute($expression);
    }

    public static function invalidPaging(): array
    {
        return ['skip' => ['skip'], 'take' => ['take']];
    }

    #[DataProvider('invalidPaging')]
    public function testNegativePagingFailsBeforeExecutingProvider(string $method): void
    {
        $query = Queryable::from(new InMemoryQueryProvider([]), 'missing');
        $this->expectException(\InvalidArgumentException::class);
        $query->$method(-1);
    }

    public function testQueryableRejectsNonIterableResultsFromCustomProvider(): void
    {
        $provider = new class implements IQueryProvider {
            public function execute(Expression $expression): mixed { return 42; }
        };
        $query = Queryable::from($provider, 'rows');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The provider did not return an iterable query result.');
        $query->toArray();
    }

    public static function invalidGroupingSelectors(): array
    {
        return ['key' => [Expr::key()], 'aggregate' => [Expr::count()]];
    }

    #[DataProvider('invalidGroupingSelectors')]
    public function testGroupExpressionsRequireGroupedInput(ValueExpression $selector): void
    {
        $query = Queryable::from(new InMemoryQueryProvider(['rows' => [['id' => 1]]]), 'rows');
        $this->expectException(\LogicException::class);
        $query->select($selector)->toArray();
    }

    public function testUnknownValueExpressionFailsExplicitly(): void
    {
        $query = Queryable::from(new InMemoryQueryProvider(['rows' => [1]]), 'rows');
        $unknown = new class implements ValueExpression {};
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unsupported value expression:');
        $query->select($unknown)->toArray();
    }

    public function testEmptyQueriesHaveNoAnyResultAndFirstThrows(): void
    {
        $query = Queryable::from(new InMemoryQueryProvider(['rows' => []]), 'rows');
        self::assertFalse($query->any());
        self::assertFalse($query->any(Expr::value(true)));
        $this->expectException(\UnderflowException::class);
        $query->first();
    }

    public function testObjectAndNestedNullableFieldPathsAndLessThanOrEqual(): void
    {
        $query = Queryable::from(new InMemoryQueryProvider(['rows' => [
            (object) ['id' => 1, 'profile' => (object) ['name' => 'Ada']],
            (object) ['id' => 2, 'profile' => null],
        ]]), 'rows');
        self::assertSame(['Ada', null], $query->select(Expr::field('profile.name'))->toArray());
        self::assertSame([1], $query->where(Expr::lte(Expr::field('id'), 1))->select(Expr::field('id'))->toArray());
    }

    public function testMissingFieldPathIsRejected(): void
    {
        $query = Queryable::from(new InMemoryQueryProvider(['rows' => [(object) ['id' => 1]]]), 'rows');
        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage('Cannot read field path: missing.name');
        $query->select(Expr::field('missing.name'))->toArray();
    }

    public function testCompositeJoinKeysWithDifferentMemberNamesDoNotMatch(): void
    {
        $query = Queryable::from(new InMemoryQueryProvider(['rows' => [['id' => 1]]]), 'rows');
        self::assertSame([], $query->join($query,
            Expr::projection(['left' => 'id']), Expr::projection(['right' => 'id']),
            Expr::field('outer.id'))->toArray());
    }

    public function testSelectManyPredicatesImplementThreeValuedAndOrLogic(): void
    {
        $values = [['id' => 'T', 'truth' => true], ['id' => 'F', 'truth' => false], ['id' => 'N', 'truth' => null]];
        $provider = new InMemoryQueryProvider(['values' => $values]);
        $query = Queryable::from($provider, 'values');
        $left = Expr::field('outer.truth');
        $right = Expr::field('inner.truth');
        $result = Expr::projection(['left' => 'outer.id', 'right' => 'inner.id']);
        self::assertSame([['left' => 'T', 'right' => 'T']],
            $query->selectMany($query, $result, Expr::both($left, $right))->toArray());
        self::assertSame([
            ['left' => 'T', 'right' => 'T'], ['left' => 'T', 'right' => 'F'], ['left' => 'T', 'right' => 'N'],
            ['left' => 'F', 'right' => 'T'], ['left' => 'N', 'right' => 'T'],
        ], $query->selectMany($query, $result, Expr::either($left, $right))->toArray());
    }
}
