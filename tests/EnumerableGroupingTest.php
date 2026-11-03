<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\Collections\Generic\EqualityComparer;
use Light\Linq\Enumerable;
use Light\Linq\Grouping;
use Light\Linq\IEnumerable;
use PHPUnit\Framework\TestCase;

final class EnumerableGroupingTest extends TestCase
{
    public function testGroupByPreservesKeyAndElementOrderAndComposes(): void
    {
        $query = Enumerable::from([
            ['key' => 'b', 'value' => 1], ['key' => 'a', 'value' => 2],
            ['key' => 'b', 'value' => 3],
        ])->groupBy(fn ($row) => $row['key']);
        $groups = $query->toArray();
        self::assertContainsOnlyInstancesOf(Grouping::class, $groups);
        self::assertSame(['b', 'a'], array_column($groups, 'key'));
        self::assertSame([1, 3], array_column(iterator_to_array($groups[0]), 'value'));
        self::assertCount(2, $groups[0]);
        self::assertSame(['b'], $query->where(fn ($g) => count($g) > 1)->select(fn ($g) => $g->key)->toArray());
        self::assertSame([], Enumerable::from([])->groupBy(fn ($x) => $x)->toArray());
    }

    public function testKeysUseStrictEqualityIncludingNullCompositeAndObjectKeys(): void
    {
        $one = (object) ['id' => 1];
        $two = (object) ['id' => 1];
        $values = [1, '1', null, null, ['id' => 1], ['id' => 1], $one, $two, $one];
        $groups = Enumerable::from($values)->groupBy(fn ($x) => $x)->toArray();
        self::assertSame([1, '1', null, ['id' => 1], $one, $two], array_column($groups, 'key'));
        self::assertSame([1, 1, 2, 2, 2, 1], array_map(count(...), $groups));
    }

    public function testCustomComparerHandlesHashCollisionsForBothOperators(): void
    {
        $comparer = new class implements EqualityComparer {
            public function hash(mixed $value): string { return 'collision'; }
            public function equals(mixed $left, mixed $right): bool {
                return strtolower($left) === strtolower($right);
            }
        };
        $groups = Enumerable::from(['B', 'A', 'b'])->groupBy(fn ($x) => $x, $comparer)->toArray();
        self::assertSame(['B', 'A'], array_column($groups, 'key'));
        self::assertSame(['B', 'b'], $groups[0]->items);
        self::assertSame([['a'], ['B', 'b'], []], Enumerable::from(['A', 'B', 'C'])->groupJoin(
            ['B', 'a', 'b'], fn ($x) => $x, fn ($x) => $x,
            fn ($outer, IEnumerable $matches) => $matches->toArray(), $comparer,
        )->toArray());
    }

    public function testGroupJoinKeepsDuplicateOuterRowsAndProvidesReusableComposableMatches(): void
    {
        $query = Enumerable::from([2, 1, 2, 3])->groupJoin(
            [['id' => 1, 'n' => 10], ['id' => 2, 'n' => 20], ['id' => 2, 'n' => 30]],
            fn ($x) => $x, fn ($x) => $x['id'],
            function ($outer, IEnumerable $matches) {
                self::assertSame($matches->toArray(), $matches->toArray());
                return [$outer, $matches->select(fn ($x) => $x['n'])->toArray(), $matches->sum(fn ($x) => $x['n'])];
            },
        );
        self::assertSame([[2, [20, 30], 50], [1, [10], 10], [2, [20, 30], 50], [3, [], 0]], $query->toArray());
        self::assertSame(4, $query->count());
    }

    public function testGroupJoinUsesExistingJoinNullAndStrictKeySemantics(): void
    {
        self::assertSame([[null], [1], ['1'], []], Enumerable::from([null, 1, '1', 2])->groupJoin(
            [null, 1, '1'], fn ($x) => $x, fn ($x) => $x,
            fn ($outer, $matches) => $matches->toArray(),
        )->toArray());
        self::assertSame([[], []], Enumerable::from([1, 2])->groupJoin(
            [], fn ($x) => $x, fn ($x) => $x, fn ($outer, $matches) => $matches->toArray(),
        )->toArray());
    }

    public function testGroupByIsDeferredBuffersSourceAndRebuildsOnEnumeration(): void
    {
        $reads = 0;
        $source = Enumerable::defer(static function () use (&$reads) {
            foreach ([1, 2, 1] as $value) { ++$reads; yield $value; }
        });
        $query = $source->groupBy(fn ($x) => $x);
        self::assertSame(0, $reads);
        self::assertSame([1, 1], $query->first()->items);
        self::assertSame(3, $reads);
        self::assertSame(2, $query->count());
        self::assertSame(6, $reads);
    }

    public function testGroupJoinBuffersInnerOnceAndStreamsOuter(): void
    {
        $innerReads = $outerReads = 0;
        $inner = Enumerable::defer(static function () use (&$innerReads) {
            foreach ([1, 1, 2] as $x) { ++$innerReads; yield $x; }
        });
        $outer = Enumerable::defer(static function () use (&$outerReads) {
            foreach ([1, 2, 3] as $x) { ++$outerReads; yield $x; }
        });
        $query = $outer->groupJoin($inner, fn ($x) => $x, fn ($x) => $x, fn ($x, $matches) => $matches->count());
        self::assertSame(0, $innerReads + $outerReads);
        self::assertSame(2, $query->first());
        self::assertSame(3, $innerReads);
        self::assertSame(1, $outerReads);
        self::assertSame([2, 1, 0], $query->toArray());
        self::assertSame(6, $innerReads);
    }

    public function testEmptyOuterDoesNotEnumerateInner(): void
    {
        $inner = (static function () { throw new \RuntimeException('Unexpected enumeration'); yield 1; })();
        self::assertSame([], Enumerable::from([])->groupJoin(
            $inner, fn ($x) => $x, fn ($x) => $x, fn ($outer, $matches) => $matches,
        )->toArray());
    }
}
