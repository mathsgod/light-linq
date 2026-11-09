<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Collections\Generic\EqualityComparer;
use Light\Linq\Enumerable;
use Light\Linq\IEnumerable;
use PHPUnit\Framework\TestCase;

final class GroupByResultSelectorTest extends TestCase
{
    public function testProjectsKeysAndReusableComposableMembersInSourceOrder(): void
    {
        $result = Enumerable::from([['city' => 'HK', 'n' => 2], ['city' => 'TW', 'n' => 3], ['city' => 'HK', 'n' => 4]])
            ->groupBy(fn ($x) => $x['city'], resultSelector: function ($key, IEnumerable $members) {
                self::assertSame($members->toArray(), $members->toArray());
                return [$key, $members->count(), $members->sum(fn ($x) => $x['n'])];
            })->toArray();
        self::assertSame([['HK', 2, 6], ['TW', 1, 3]], $result);
    }

    public function testComparerRetainsFirstKeyAndResultSelectorCanReturnNull(): void
    {
        $comparer = new class implements EqualityComparer {
            public function hash(mixed $value): string { return 'collision'; }
            public function equals(mixed $left, mixed $right): bool { return strtolower($left) === strtolower($right); }
        };
        self::assertSame([['A', ['A', 'a']], ['B', ['B']]], Enumerable::from(['A', 'B', 'a'])
            ->groupBy(fn ($x) => $x, $comparer, fn ($key, $members) => [$key, $members->toArray()])->toArray());
        self::assertSame([null], Enumerable::from([1, 1])->groupBy(fn ($x) => $x, resultSelector: fn ($k, $m) => null)->toArray());
        self::assertSame([[null, 2]], Enumerable::from([null, null])->groupBy(fn ($x) => $x, resultSelector: fn ($k, $m) => [$k, $m->count()])->toArray());
    }

    public function testDeferredExecutionBuffersSourceButProjectsGroupsOnDemandAndReplays(): void
    {
        $reads = $calls = 0;
        $query = Enumerable::defer(static function () use (&$reads): iterable {
            foreach ([1, 2, 1] as $x) { ++$reads; yield $x; }
        })->groupBy(fn ($x) => $x, resultSelector: function ($key, $members) use (&$calls) {
            ++$calls;
            return [$key, $members];
        });
        self::assertSame([0, 0], [$reads, $calls]);
        [$key, $members] = $query->first();
        self::assertSame([3, 1], [$reads, $calls]);
        self::assertSame(1, $key);
        self::assertSame([1, 1], $members->toArray());
        self::assertSame(2, $query->count());
        self::assertSame([6, 3], [$reads, $calls]);
        self::assertSame([1, 1], $members->toArray());
    }

    public function testEmptyInputDoesNotInvokeSelectorAndFailuresAreDeferred(): void
    {
        $fail = fn ($key, $members) => throw new \RuntimeException('projection failed');
        self::assertSame([], Enumerable::empty()->groupBy(fn ($x) => $x, resultSelector: $fail)->toArray());
        $query = Enumerable::from([1])->groupBy(fn ($x) => $x, resultSelector: $fail);
        $this->expectExceptionMessage('projection failed');
        $query->toArray();
    }
}
