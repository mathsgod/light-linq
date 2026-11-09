<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\Enumerable;
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
{
    public function testOrdersElementsInBothDirections(): void
    {
        $source = Enumerable::from([3, 1, 2, 1]);
        self::assertSame([1, 1, 2, 3], $source->order()->toArray());
        self::assertSame([3, 2, 1, 1], $source->orderDescending()->toArray());
        self::assertSame([], Enumerable::empty()->order()->toArray());
        self::assertSame([1], Enumerable::from([1])->orderDescending()->toArray());
    }

    public function testComparerReceivesElementsAndTiesSupportSecondaryOrdering(): void
    {
        $rows = [['group' => 'a', 'id' => 2], ['group' => 'B', 'id' => 3], ['group' => 'A', 'id' => 1]];
        $compare = static fn (array $a, array $b): int => strcasecmp($a['group'], $b['group']);
        $source = Enumerable::from($rows);
        self::assertSame([2, 1, 3], $source->order(comparer: $compare)->select(fn ($x) => $x['id'])->toArray());
        self::assertSame([3, 2, 1], $source->orderDescending($compare)->select(fn ($x) => $x['id'])->toArray());
        self::assertSame([1, 2, 3], $source->order($compare)->thenBy(fn ($x) => $x['id'])->select(fn ($x) => $x['id'])->toArray());
    }

    public function testBothDirectionsDeferAndRepeatSourceEnumeration(): void
    {
        foreach (['order', 'orderDescending'] as $method) {
            $reads = 0;
            $source = Enumerable::defer(static function () use (&$reads): iterable {
                ++$reads;
                yield from [2, 1];
            });
            $query = $source->$method();
            self::assertSame(0, $reads);
            $expected = $method === 'order' ? [1, 2] : [2, 1];
            self::assertSame($expected, $query->toArray());
            self::assertSame($expected, $query->toArray());
            self::assertSame(2, $reads);
        }
    }
}
