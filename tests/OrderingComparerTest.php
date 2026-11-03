<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\Enumerable;
use PHPUnit\Framework\TestCase;

final class OrderingComparerTest extends TestCase
{
    public function testComparerReceivesSelectedKeysAndCaseInsensitiveTiesAreStable(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'bob'], ['id' => 2, 'name' => 'Ada'],
            ['id' => 3, 'name' => 'ada'], ['id' => 4, 'name' => 'Bob'],
        ];
        $key = fn ($row) => $row['name'];
        $compare = static fn (string $left, string $right): int => strcasecmp($left, $right);
        self::assertSame([2, 3, 1, 4], Enumerable::from($rows)->orderBy($key, $compare)->select(fn ($row) => $row['id'])->toArray());
        self::assertSame([1, 4, 2, 3], Enumerable::from($rows)->orderByDescending($key, $compare)->select(fn ($row) => $row['id'])->toArray());
    }

    public function testNamedComparerSupportsNaturalSorting(): void
    {
        $source = Enumerable::from(['file10', 'file2', 'file1']);
        self::assertSame(['file1', 'file2', 'file10'], $source->orderBy(fn ($value) => $value, comparer: 'strnatcmp')->toArray());
        self::assertSame(['file10', 'file2', 'file1'], $source->orderByDescending(fn ($value) => $value, 'strnatcmp')->toArray());
    }

    public function testEveryOrderingLevelUsesItsOwnComparer(): void
    {
        $rows = [
            ['group' => 'A', 'file' => 'file10', 'id' => 1],
            ['group' => 'a', 'file' => 'file2', 'id' => 2],
            ['group' => 'B', 'file' => 'file1', 'id' => 3],
            ['group' => 'a', 'file' => 'file2', 'id' => 4],
        ];
        $query = Enumerable::from($rows)->orderBy(fn ($row) => $row['group'], 'strcasecmp')
            ->thenBy(fn ($row) => $row['file'], 'strnatcmp')
            ->thenByDescending(fn ($row) => $row['id']);
        self::assertSame([4, 2, 1, 3], $query->select(fn ($row) => $row['id'])->toArray());
        self::assertSame([1, 2, 4, 3], Enumerable::from($rows)->orderBy(fn ($row) => $row['group'], 'strcasecmp')
            ->thenByDescending(fn ($row) => $row['file'], 'strnatcmp')
            ->select(fn ($row) => $row['id'])->toArray());
    }

    public function testDefaultPrimaryCanBeCombinedWithCustomSecondaryComparer(): void
    {
        $rows = [['g' => 2, 'v' => 'a'], ['g' => 1, 'v' => 'file10'], ['g' => 1, 'v' => 'file2']];
        self::assertSame(['file2', 'file10', 'a'], Enumerable::from($rows)->orderBy(fn ($row) => $row['g'])
            ->thenBy(fn ($row) => $row['v'], 'strnatcmp')->select(fn ($row) => $row['v'])->toArray());
    }

    public function testNewPrimaryOrderReplacesPreviousOrderingCriteria(): void
    {
        $query = Enumerable::from(['file2', 'file10', 'file1'])->orderBy(fn ($x) => $x, 'strnatcmp')
            ->orderBy(fn ($x) => $x);
        self::assertSame(['file1', 'file10', 'file2'], $query->toArray());
    }

    public function testAllEqualComparerRetainsSourceOrderInBothDirections(): void
    {
        foreach (['orderBy', 'orderByDescending'] as $method) {
            self::assertSame([3, 1, 2], Enumerable::from([3, 1, 2])->$method(fn ($x) => $x, fn ($a, $b) => 0)->toArray());
        }
        foreach (['thenBy', 'thenByDescending'] as $method) {
            self::assertSame([3, 1, 2], Enumerable::from([3, 1, 2])->orderBy(fn ($x) => 1)
                ->$method(fn ($x) => $x, fn ($a, $b) => 0)->toArray());
        }
    }

    public function testDescendingSupportsExtremeIntegerComparisonResultsWithoutOverflow(): void
    {
        $compare = static fn (int $left, int $right): int => $left === $right ? 0 : ($left < $right ? PHP_INT_MIN : PHP_INT_MAX);
        self::assertSame([3, 2, 1], Enumerable::from([2, 1, 3])->orderByDescending(fn ($x) => $x, $compare)->toArray());
        self::assertSame([3, 2, 1], Enumerable::from([2, 1, 3])->orderBy(fn ($x) => 1)
            ->thenByDescending(fn ($x) => $x, $compare)->toArray());
    }

    public function testSortingDefersWorkAndRebuildsResultsOnEnumeration(): void
    {
        $reads = $calls = 0;
        $source = Enumerable::defer(static function () use (&$reads): iterable {
            foreach (['file10', 'file2', 'file1'] as $value) { ++$reads; yield $value; }
        });
        $query = $source->orderBy(fn ($x) => $x, static function ($left, $right) use (&$calls): int {
            ++$calls;
            return strnatcmp($left, $right);
        });
        self::assertSame([0, 0], [$reads, $calls]);
        self::assertSame('file1', $query->first());
        self::assertSame(3, $reads);
        self::assertGreaterThan(0, $calls);
        $previousCalls = $calls;
        self::assertSame(['file1', 'file2', 'file10'], $query->toArray());
        self::assertSame(6, $reads);
        self::assertGreaterThan($previousCalls, $calls);
    }

    public function testEmptyAndSingletonSourcesDoNotInvokeComparer(): void
    {
        $bad = fn () => throw new \RuntimeException('Unexpected comparison');
        self::assertSame([], Enumerable::empty()->orderBy(fn ($x) => $x, $bad)->toArray());
        self::assertSame([1], Enumerable::from([1])->orderByDescending(fn ($x) => $x, $bad)
            ->thenBy(fn ($x) => $x, $bad)->toArray());
    }

    public function testComparerExceptionsArePropagatedDuringEnumeration(): void
    {
        $failure = new \RuntimeException('Comparison failed');
        $query = Enumerable::from([1, 2])->orderBy(fn ($x) => $x, fn () => throw $failure);
        try {
            $query->toArray();
            self::fail('Expected comparer failure');
        } catch (\RuntimeException $error) {
            self::assertSame($failure, $error);
        }
    }
}
