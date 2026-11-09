<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Collections\Generic\GenericList;
use Light\Collections\Generic\HashSet;
use Light\Linq\Enumerable;
use Light\Linq\Grouping;
use PHPUnit\Framework\TestCase;

final class NonEnumeratedCountTest extends TestCase
{
    public function testKnownCountsIncludeEmptyAndGeneratedSequences(): void
    {
        foreach ([
            [Enumerable::from(['a' => 1, 'b' => 2]), 2],
            [Enumerable::empty(), 0],
            [Enumerable::range(10, 4), 4],
            [Enumerable::repeat('x', 3), 3],
            [Enumerable::from(new HashSet([1, 1, 2])), 2],
            [Enumerable::from(new Grouping('a', [1, 2])), 2],
            [Enumerable::from(Enumerable::from([1, 2])->toDictionary(fn ($x) => $x)), 2],
            [Enumerable::from(Enumerable::from([1, 1, 2])->toLookup(fn ($x) => $x)), 2],
        ] as [$query, $expected]) {
            $count = null;
            self::assertTrue($query->tryGetNonEnumeratedCount($count));
            self::assertSame($expected, $count);
        }
    }

    public function testMutableCollectionCountIsReadAtCallTime(): void
    {
        $list = new GenericList([1]);
        $query = Enumerable::from($list);
        $count = null;
        self::assertTrue($query->tryGetNonEnumeratedCount($count));
        self::assertSame(1, $count);
        $list->add(2);
        self::assertTrue($query->asEnumerable()->tryGetNonEnumeratedCount($count));
        self::assertSame(2, $count);
    }

    public function testUnknownCountDoesNotReadOrConsumeGenerator(): void
    {
        $reads = 0;
        $generator = (static function () use (&$reads): iterable {
            ++$reads;
            yield 1;
        })();
        $query = Enumerable::from($generator);
        $count = 99;
        self::assertFalse($query->tryGetNonEnumeratedCount($count));
        self::assertSame(0, $count);
        self::assertSame(0, $reads);
        self::assertSame([1], $query->toArray());
    }

    public function testUnknownPipelinesDoNotExecuteCallbacks(): void
    {
        $fail = static fn () => throw new \RuntimeException('Must not execute');
        foreach ([Enumerable::defer($fail), Enumerable::from([1])->where($fail), Enumerable::from([1])->select($fail)] as $query) {
            $count = null;
            self::assertFalse($query->tryGetNonEnumeratedCount($count));
            self::assertSame(0, $count);
        }
    }

    public function testArbitraryCountableIsNotTrusted(): void
    {
        $source = new class implements \IteratorAggregate, \Countable {
            public function count(): int { throw new \RuntimeException('Must not count'); }
            public function getIterator(): \Traversable { throw new \RuntimeException('Must not enumerate'); }
        };
        $count = null;
        self::assertFalse(Enumerable::from($source)->tryGetNonEnumeratedCount($count));
        self::assertSame(0, $count);
    }
}
