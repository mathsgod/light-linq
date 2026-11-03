<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Collections\Generic\{GenericList, HashSet, Queue, Stack};
use Light\Linq\Enumerable;
use PHPUnit\Framework\TestCase;

final class CollectionIntegrationTest extends TestCase
{
    public function testCollectionsCanBeQueriedAsEnumerable(): void
    {
        $list = new GenericList([1, 2, 3, 4]);
        $stack = new Stack([1, 2, 3]);
        $queue = new Queue([1, 2, 3]);

        self::assertSame(
            [20, 40],
            Enumerable::from($list)
                ->where(static fn (int $number): bool => $number % 2 === 0)
                ->select(static fn (int $number): int => $number * 10)
                ->toArray(),
        );
        self::assertSame([3, 2, 1], Enumerable::from($stack)->toArray());
        self::assertSame([1, 2, 3], Enumerable::from($queue)->toArray());
    }

    public function testHashSetCanBeQueriedThroughEnumerable(): void
    {
        $set = new HashSet([1, 2, 3, 4]);

        self::assertSame(
            [20, 40],
            Enumerable::from($set)
                ->where(static fn (int $value): bool => $value % 2 === 0)
                ->select(static fn (int $value): int => $value * 10)
                ->toArray(),
        );
    }

}
