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

final class QueryableOrderingIndexCountTest extends TestCase
{
    private function query(iterable $rows): Queryable
    {
        return Queryable::from(new InMemoryQueryProvider(['rows' => $rows]), 'rows');
    }

    public function testIdentityOrderingSupportsBothDirectionsAndSecondaryOrdering(): void
    {
        $query = $this->query([3, 1, 2, 1]);
        self::assertSame([1, 1, 2, 3], $query->order()->toArray());
        self::assertSame([3, 2, 1, 1], $query->orderDescending()->toArray());
        self::assertSame([3, 1, 2, 1], $query->toArray());
        self::assertSame([], $this->query([])->order()->toArray());
        self::assertSame(['Ada', 'Bob'], $this->query(['Bob', 'Ada'])->order()->toArray());
        // Equal primary and secondary keys retain the original insertion order.
        $rows = [['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]];
        self::assertSame($rows, $this->query($rows)->order()
            ->thenByDescending(Expr::field('a'))->toArray());
    }

    public function testPrimaryOrderResetsPriorCriteriaAndThenByChainWorks(): void
    {
        $rows = [['n' => 2], ['n' => 1], ['n' => 3]];
        self::assertSame([['n' => 1], ['n' => 2], ['n' => 3]], $this->query($rows)
            ->orderByDescending(Expr::field('n'))->order()->thenBy(Expr::field('n'))->thenByDescending(Expr::field('n'))->toArray());
        self::assertSame([3, 2, 1], $this->query($rows)->select(Expr::field('n'))->orderDescending()->toArray());
    }

    public function testElementAccessIgnoresKeysAndDistinguishesNullFromMissing(): void
    {
        $query = $this->query(['a' => 'A', 90 => null, 'c' => false]);
        self::assertSame('A', $query->elementAt(0));
        self::assertNull($query->elementAt(1));
        self::assertFalse($query->elementAt(2));
        self::assertNull($query->elementAtOrDefault(1, 'fallback'));
        self::assertSame('fallback', $query->elementAtOrDefault(3, 'fallback'));
        self::assertNull($this->query([])->elementAtOrDefault(0));
        self::assertSame('A', $query->reverse()->elementAt(2));
        foreach ([-1, 3] as $index) {
            try {
                $query->elementAt($index);
                self::fail('Expected out of bounds');
            } catch (\OutOfBoundsException) {
                self::assertTrue(true);
            }
        }
    }

    public function testNegativeIndexesDoNotExecuteProviderAndPositiveIndexesAreTerminal(): void
    {
        $provider = new class implements IQueryProvider {
            public array $calls = [];
            public function execute(Expression $expression): mixed { $this->calls[] = $expression; return 42; }
        };
        $query = Queryable::from($provider, 'rows');
        self::assertSame('default', $query->elementAtOrDefault(-1, 'default'));
        try { $query->elementAt(-1); self::fail('Expected invalid index'); } catch (\OutOfBoundsException) {}
        self::assertSame([], $provider->calls);
        self::assertSame(42, $query->elementAt(2));
        self::assertSame(42, $query->elementAtOrDefault(2, 'fallback'));
        self::assertSame(['elementAt', 'elementAtOrDefault'], array_map(fn ($e) => $e->method, $provider->calls));
        self::assertSame(['index' => 2, 'default' => 'fallback'], $provider->calls[1]->arguments);
    }

    public function testCountByReturnsPairsInFirstKeyOrderAndSupportsComposition(): void
    {
        $rows = [['city' => 'HK'], ['city' => 'TW'], ['city' => 'HK'], ['city' => null]];
        $query = $this->query($rows)->countBy(fn ($row) => $row->city);
        $projection = Expr::projection(['key' => 'key', 'count' => 'value']);
        self::assertSame([['key' => 'HK', 'count' => 2], ['key' => 'TW', 'count' => 1], ['key' => null, 'count' => 1]], $query->select($projection)->toArray());
        self::assertSame(['HK'], $query->where(Expr::gt(Expr::field('value'), 1))->select(Expr::field('key'))->toArray());
        self::assertSame(3, $query->count());
        self::assertSame([], $this->query([])->countBy(Expr::field('city'))->toArray());
        self::assertSame([1, '1', null, ['x' => 1]], $this->query(array_map(fn ($v) => ['k' => $v], [1, '1', null, null, ['x' => 1]]))
            ->countBy(Expr::field('k'))->select(Expr::field('key'))->toArray());
    }

    public function testLazinessReplayBufferingAndElementAccessDoesNotReadAhead(): void
    {
        $reads = 0;
        $rows = Enumerable::defer(static function () use (&$reads): iterable {
            foreach ([3, 1, 3] as $n) { ++$reads; yield ['n' => $n]; }
        });
        $source = $this->query($rows);
        $sorted = $source->select(Expr::field('n'))->order();
        $counts = $source->countBy(Expr::field('n'));
        self::assertSame(0, $reads);
        self::assertSame(['n' => 3], $source->elementAt(0));
        self::assertSame(1, $reads);
        self::assertSame([1, 3, 3], $sorted->toArray());
        self::assertSame(4, $reads);
        self::assertSame(2, $counts->elementAt(0)->value);
        self::assertSame(7, $reads);
        self::assertSame(2, $counts->count());
        self::assertSame(10, $reads);
    }
}
