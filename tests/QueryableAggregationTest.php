<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\ClosureTranslationException;
use Light\Linq\Enumerable;
use Light\Linq\Expr;
use Light\Linq\Expressions\BinaryExpression;
use Light\Linq\Expressions\Expression;
use Light\Linq\InMemoryQueryProvider;
use Light\Linq\IQueryProvider;
use Light\Linq\Queryable;
use PHPUnit\Framework\TestCase;

final class QueryableAggregationTest extends TestCase
{
    public function testAsQueryableIsDeferredReusableAndPreservesExistingQueryIdentity(): void
    {
        $reads = 0;
        $source = Enumerable::defer(static function () use (&$reads): iterable {
            foreach ([3, 1, 2] as $n) { ++$reads; yield ['n' => $n]; }
        });
        $query = $source->asQueryable();
        self::assertSame(0, $reads);
        self::assertSame($query, Queryable::asQueryable($query));
        $sorted = $query->orderBy(fn ($row) => $row->n)
            ->select(fn ($row) => $row->n);
        self::assertSame([1, 2, 3], $sorted->toArray());
        self::assertSame([1, 2, 3], $sorted->toArray());
        self::assertSame(6, $reads);
        self::assertSame([], Queryable::asQueryable([])->toArray());
        self::assertSame([1, 2], Queryable::asQueryable(['a' => 1, 'b' => 2])->toArray());
    }

    public function testSingleUseGeneratorIsNotConsumedDuringConversion(): void
    {
        $reads = 0;
        $source = (static function () use (&$reads): iterable { ++$reads; yield 7; })();
        $query = Queryable::asQueryable($source);
        self::assertSame(0, $reads);
        self::assertSame(7, $query->first());
        self::assertSame(1, $reads);
    }

    public function testAggregateWithAndWithoutSeedAndExplicitNullSeed(): void
    {
        $query = Queryable::asQueryable([10, 3, 2]);
        self::assertSame(5, $query->aggregate(fn ($acc, $item) => $acc - $item));
        self::assertSame(85, $query->aggregate(fn ($acc, $item) => $acc - $item, 100));
        self::assertSame(15, $query->aggregate(fn ($acc, $item) => ($acc ?? 0) + $item, null));
        self::assertSame(42, Queryable::asQueryable([42])->aggregate(fn ($acc, $item) => $acc + $item));
        self::assertNull(Queryable::asQueryable([null])->aggregate(fn ($acc, $item) => $item));
    }

    public function testAggregateResultSelectorAndStructuredState(): void
    {
        $query = Queryable::asQueryable([['amount' => 10], ['amount' => 20]]);
        $result = $query->aggregate(
            fn ($acc, $row) => ['sum' => $acc->sum + $row->amount, 'count' => $acc->count + 1],
            seed: ['sum' => 0, 'count' => 0],
            resultSelector: fn ($state) => $state->sum / $state->count,
        );
        self::assertSame(15, $result);
        self::assertSame(['total' => 30], $query->aggregate(
            fn ($acc, $row) => $acc + $row->amount,
            0,
            fn ($result) => ['total' => $result],
        ));
    }

    public function testEmptyAggregateUsesSeedOrThrowsWithoutSeed(): void
    {
        $empty = Queryable::asQueryable([]);
        self::assertSame(10, $empty->aggregate(fn ($acc, $item) => $acc + $item, 10));
        self::assertNull($empty->aggregate(fn ($acc, $item) => $acc, null));
        self::assertSame(20, $empty->aggregate(fn ($acc, $item) => $acc + $item, 10, fn ($result) => $result * 2));
        $this->expectException(\UnderflowException::class);
        $empty->aggregate(fn ($acc, $item) => $acc + $item);
    }

    public function testAggregateByReturnsKeyValuePairsAndComposes(): void
    {
        $query = Queryable::asQueryable([
            ['city' => 'HK', 'amount' => 10], ['city' => 'TW', 'amount' => 20], ['city' => 'HK', 'amount' => 5],
        ])->aggregateBy(
            fn ($row) => $row->city,
            100,
            fn ($sum, $row) => $sum + $row->amount,
        );
        $projected = $query->select(Expr::projection(['city' => 'key', 'total' => 'value']));
        $expected = [['city' => 'HK', 'total' => 115], ['city' => 'TW', 'total' => 120]];
        self::assertSame($expected, $projected->toArray());
        self::assertSame($expected, $projected->toArray());
        self::assertSame(['TW'], $query->where(fn ($pair) => $pair->value > 115)
            ->select(fn ($pair) => $pair->key)->toArray());
        self::assertSame([], Queryable::asQueryable([])->aggregateBy(fn ($row) => $row->city, 0, fn ($acc, $row) => $acc + 1)->toArray());
    }

    public function testAggregateByUsesStrictKeyEqualityAndIndependentArrayState(): void
    {
        $keys = [1, '1', null, null, ['a' => 1], ['a' => 1]];
        $query = Queryable::asQueryable(array_map(fn ($key) => ['key' => $key], $keys))->aggregateBy(
            fn ($row) => $row->key,
            ['count' => 0],
            fn ($acc, $row) => ['count' => $acc->count + 1],
        );
        self::assertSame([1, '1', null, ['a' => 1]], $query->select(fn ($pair) => $pair->key)->toArray());
        self::assertSame([1, 1, 2, 2], $query->select(fn ($pair) => $pair->value->count)->toArray());
    }

    public function testExplicitExpressionsMatchClosureResults(): void
    {
        $accumulator = new BinaryExpression(Expr::field('accumulator'), '+', Expr::field('item.n'));
        $query = Queryable::asQueryable([['n' => 2, 'k' => 'A'], ['n' => 3, 'k' => 'A']]);
        self::assertSame(5, $query->aggregate($accumulator, 0));
        self::assertSame(10, $query->aggregate($accumulator, 0, new BinaryExpression(Expr::field('result'), '*', Expr::value(2))));
        self::assertSame(5, $query->aggregateBy(Expr::field('k'), 0, $accumulator)->first()->value);
    }

    public function testDeferredGroupedExecutionAndImmediateAggregateExecution(): void
    {
        $provider = new class implements IQueryProvider {
            public array $calls = [];
            public function execute(Expression $expression): mixed { $this->calls[] = $expression; return 123; }
        };
        $query = Queryable::from($provider, 'rows');
        $grouped = $query->aggregateBy(fn ($row) => $row->key, 0, fn ($acc, $row) => $acc + $row->n);
        self::assertSame([], $provider->calls);
        self::assertSame('aggregateBy', $grouped->expression()->method);
        self::assertSame($provider, $grouped->provider());
        self::assertSame(123, $query->aggregate(fn ($acc, $item) => $acc + $item));
        self::assertFalse($provider->calls[0]->arguments['hasSeed']);
        self::assertSame(123, $query->aggregate(fn ($acc, $item) => $acc + $item, null));
        self::assertTrue($provider->calls[1]->arguments['hasSeed']);
    }

    public function testCorrelatedAnyCanReferenceAccumulatorAndCurrentElement(): void
    {
        $provider = new InMemoryQueryProvider(['rows' => [['n' => 1], ['n' => 2]], 'keys' => [['n' => 1]]]);
        $query = Queryable::from($provider, 'rows');
        $keys = Queryable::from($provider, 'keys');
        self::assertSame(1, $query->aggregate(
            fn ($acc, $item) => $keys->any(fn ($key) => $key->n == $item->n) ? $acc + $item->n : $acc,
            0,
        ));
        self::assertSame(1, $query->aggregate(
            fn ($acc, $item) => $keys->any(fn ($key) => $key->n > $acc) ? $acc + 1 : $acc,
            0,
        ));
    }

    public function testUnsupportedAccumulatorSyntaxAndParameterCountsFailBeforeExecution(): void
    {
        $query = Queryable::asQueryable([]);
        $invalid = [
            fn ($acc) => $acc,
            fn ($acc, $item, $index) => $acc,
            fn ($acc, &$item) => $acc,
            fn ($acc, $item) => md5($item),
        ];
        foreach ($invalid as $closure) {
            try { $query->aggregate($closure, 0); self::fail('Expected translation failure'); }
            catch (ClosureTranslationException) { self::assertTrue(true); }
        }
    }
}
