<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\Enumerable;
use Light\Linq\Expr;
use Light\Linq\Expressions\Expression;
use Light\Linq\Expressions\MethodCallExpression;
use Light\Linq\InMemoryQueryProvider;
use Light\Linq\IQueryProvider;
use Light\Linq\Queryable;
use PHPUnit\Framework\TestCase;

final class QueryableKeyOperatorsTest extends TestCase
{
    private function queries(): array
    {
        $provider = new InMemoryQueryProvider([
            'people' => [
                ['id' => 1, 'name' => 'Ada', 'age' => 30],
                ['id' => 2, 'name' => 'Bob', 'age' => 20],
                ['id' => 1, 'name' => 'Ada2', 'age' => 40],
            ],
            'other' => [['id' => 2, 'name' => 'Bob2', 'age' => 22], ['id' => 3, 'name' => 'Cara', 'age' => 20]],
            'keys' => [['id' => 1], ['id' => 1]], 'empty' => [],
        ]);
        return array_map(fn ($name) => Queryable::from($provider, $name), ['people', 'other', 'keys', 'empty']);
    }

    public function testDistinctAndSetOperationsKeepCompleteFirstElementsAndCompose(): void
    {
        [$people, $other, $keys] = $this->queries();
        $ids = $keys->select(Expr::field('id'));
        self::assertSame(['Ada', 'Bob'], $people->distinctBy(Expr::field('id'))->select(Expr::field('name'))->toArray());
        self::assertSame(['Bob'], $people->exceptBy($ids, fn ($p) => $p->id)->select(Expr::field('name'))->toArray());
        self::assertSame(['Ada'], $people->intersectBy($ids, Expr::field('id'))->select(Expr::field('name'))->toArray());
        self::assertSame(['Ada', 'Bob', 'Cara'], $people->unionBy($other, fn ($p) => $p->id)->select(Expr::field('name'))->toArray());
        self::assertSame(3, $people->count());
        self::assertSame(['Bob', 'Ada'], $people->distinctBy(fn ($p) => $p->id)->orderBy(Expr::field('age'))->select(Expr::field('name'))->toArray());
    }

    public function testExtremeOperatorsReturnWholeRowsAndFirstTie(): void
    {
        [$people, $other] = $this->queries();
        self::assertSame(['id' => 2, 'name' => 'Bob', 'age' => 20], $people->minBy(fn ($p) => $p->age));
        self::assertSame(['id' => 1, 'name' => 'Ada2', 'age' => 40], $people->maxBy(Expr::field('age')));
        self::assertSame('Bob', $people->concat($other)->minBy(Expr::field('age'))['name']);
        self::assertSame('Ada', $people->minBy(Expr::value(0))['name']);
        self::assertSame('Ada', $people->maxBy(Expr::value(0))['name']);
    }

    public function testStrictNullAndCompositeKeySemanticsMatchEnumerable(): void
    {
        $values = [1, '1', null, null, ['x' => 1], ['x' => 1]];
        $provider = new InMemoryQueryProvider(['rows' => array_map(fn ($k) => ['k' => $k], $values), 'keys' => [null, ['x' => 1]]]);
        $query = Queryable::from($provider, 'rows');
        $keys = Queryable::from($provider, 'keys');
        self::assertSame([1, '1', null, ['x' => 1]], $query->distinctBy(Expr::field('k'))->select(Expr::field('k'))->toArray());
        self::assertSame([null, ['x' => 1]], $query->intersectBy($keys, Expr::field('k'))->select(Expr::field('k'))->toArray());
        self::assertSame([1, '1'], $query->exceptBy($keys, Expr::field('k'))->select(Expr::field('k'))->toArray());
    }

    public function testEmptySourcesAndSecondQueries(): void
    {
        [$people, $other, $keys, $empty] = $this->queries();
        self::assertSame([], $empty->distinctBy(Expr::field('id'))->toArray());
        self::assertSame([], $people->intersectBy($empty, Expr::field('id'))->toArray());
        self::assertSame(2, $people->exceptBy($empty, Expr::field('id'))->count());
        self::assertSame(2, $empty->unionBy($other, Expr::field('id'))->count());
        foreach (['minBy', 'maxBy'] as $method) {
            try {
                $empty->$method(Expr::field('id'));
                self::fail('Expected empty sequence failure');
            } catch (\UnderflowException) {
                self::assertTrue(true);
            }
        }
    }

    public function testDifferentProvidersAreRejectedWhenBuildingQuery(): void
    {
        [$people] = $this->queries();
        $foreign = Queryable::from(new InMemoryQueryProvider([]), 'missing');
        foreach (['exceptBy', 'intersectBy', 'unionBy'] as $method) {
            try {
                $people->$method($foreign, Expr::field('id'));
                self::fail('Expected provider mismatch');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testSequenceOperatorsAreDeferredWhileExtremesExecuteImmediately(): void
    {
        $provider = new class implements IQueryProvider {
            public array $calls = [];
            public function execute(Expression $expression): mixed { $this->calls[] = $expression; return ['winner']; }
        };
        $query = Queryable::from($provider, 'rows');
        foreach (['distinctBy', 'exceptBy', 'intersectBy', 'unionBy'] as $method) {
            $next = $method === 'distinctBy' ? $query->$method(Expr::field('id')) : $query->$method($query, Expr::field('id'));
            self::assertInstanceOf(MethodCallExpression::class, $next->expression());
            self::assertSame($method, $next->expression()->method);
            self::assertSame($provider, $next->provider());
            self::assertSame($query->expression(), $next->expression()->source);
        }
        self::assertSame([], $provider->calls);
        self::assertSame(['winner'], $query->minBy(Expr::field('id')));
        self::assertSame(['winner'], $query->maxBy(Expr::field('id')));
        self::assertSame(['minBy', 'maxBy'], array_map(fn ($e) => $e->method, $provider->calls));
    }

    public function testGeneratorsAreReadOncePerExecutionAndUnionStreamsFirstSource(): void
    {
        $firstReads = $secondReads = 0;
        $rows = Enumerable::defer(static function () use (&$firstReads): iterable {
            foreach ([1, 1, 2] as $id) { ++$firstReads; yield ['id' => $id]; }
        });
        $second = Enumerable::defer(static function () use (&$secondReads): iterable {
            foreach ([2, 3] as $id) { ++$secondReads; yield ['id' => $id]; }
        });
        $provider = new InMemoryQueryProvider(['rows' => $rows, 'second' => $second]);
        $query = Queryable::from($provider, 'rows');
        $other = Queryable::from($provider, 'second');
        $union = $query->unionBy($other, Expr::field('id'));
        self::assertSame([0, 0], [$firstReads, $secondReads]);
        self::assertSame([['id' => 1]], $union->take(1)->toArray());
        self::assertSame([1, 0], [$firstReads, $secondReads]);
        self::assertSame([['id' => 2]], $query->intersectBy($other->select(Expr::field('id')), Expr::field('id'))->toArray());
        self::assertSame([4, 2], [$firstReads, $secondReads]);
        self::assertSame([['id' => 2]], $query->intersectBy($other->select(Expr::field('id')), Expr::field('id'))->toArray());
        self::assertSame([7, 4], [$firstReads, $secondReads]);
    }
}
