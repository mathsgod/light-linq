<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Collections\Generic\EqualityComparer;
use Light\Collections\Generic\KeyValuePair;
use Light\Linq\Enumerable;
use Light\Linq\IEnumerable;
use PHPUnit\Framework\TestCase;

final class KeyOperatorsTest extends TestCase
{
    private function rows(): IEnumerable
    {
        return Enumerable::from([
            ['id' => 2, 'value' => 10], ['id' => 1, 'value' => 20],
            ['id' => 2, 'value' => 30], ['id' => 3, 'value' => 40],
        ]);
    }

    private function pairs(IEnumerable $query): array
    {
        return $query->select(fn (KeyValuePair $pair) => [$pair->key, $pair->value])->toArray();
    }

    public function testExceptAndIntersectUseKeySequencesAndKeepFirstRowPerKey(): void
    {
        $key = fn ($row) => $row['id'];
        self::assertSame([['id' => 2, 'value' => 10]], $this->rows()->exceptBy([1, 1, 3], $key)->toArray());
        self::assertSame([['id' => 2, 'value' => 10], ['id' => 3, 'value' => 40]],
            $this->rows()->intersectBy([3, 2, 2], $key)->toArray());
        self::assertSame([2, 1, 3], $this->rows()->exceptBy([], $key)->select($key)->toArray());
        self::assertSame([], $this->rows()->intersectBy([], $key)->toArray());
        self::assertSame([], Enumerable::empty()->exceptBy([1], $key)->toArray());
        self::assertSame([], Enumerable::empty()->intersectBy([1], $key)->toArray());
    }

    public function testUnionUsesRowsAndKeepsFirstOccurrenceAcrossSources(): void
    {
        $key = fn ($row) => $row['id'];
        $second = [['id' => 1, 'value' => 99], ['id' => 4, 'value' => 50], ['id' => 4, 'value' => 60]];
        self::assertSame([
            ['id' => 2, 'value' => 10], ['id' => 1, 'value' => 20],
            ['id' => 3, 'value' => 40], ['id' => 4, 'value' => 50],
        ], $this->rows()->unionBy($second, $key)->toArray());
        self::assertSame([2, 1, 3], $this->rows()->unionBy([], $key)->select($key)->toArray());
        self::assertSame([1, 4], Enumerable::empty()->unionBy($second, $key)->select($key)->toArray());
    }

    public function testCountByReturnsPairsInFirstKeyOrder(): void
    {
        $query = $this->rows()->countBy(fn ($row) => $row['id']);
        self::assertContainsOnlyInstancesOf(KeyValuePair::class, $query->toArray());
        self::assertSame([[2, 2], [1, 1], [3, 1]], $this->pairs($query));
        self::assertSame([2], $query->where(fn ($pair) => $pair->value > 1)->select(fn ($pair) => $pair->key)->toArray());
    }

    public function testAggregateUsesSeedForEveryNewKeyAndIncludesFirstItem(): void
    {
        self::assertSame([[2, 140], [1, 120], [3, 140]], $this->pairs($this->rows()->aggregateBy(
            fn ($row) => $row['id'], 100, fn ($total, $row) => $total + $row['value'],
        )));
        self::assertSame([[2, ['count' => 2, 'sum' => 40]], [1, ['count' => 1, 'sum' => 20]], [3, ['count' => 1, 'sum' => 40]]],
            $this->pairs($this->rows()->aggregateBy(fn ($row) => $row['id'], ['count' => 0, 'sum' => 0],
                fn ($acc, $row) => ['count' => $acc['count'] + 1, 'sum' => $acc['sum'] + $row['value']])));
    }

    public function testEmptyAggregatesDoNotInvokeCallbacksOrEmitSeed(): void
    {
        $unexpected = fn () => throw new \RuntimeException('Unexpected callback');
        self::assertSame([], Enumerable::empty()->countBy($unexpected)->toArray());
        self::assertSame([], Enumerable::empty()->aggregateBy($unexpected, 10, $unexpected)->toArray());
    }

    public function testNullFalseCompositeAndObjectKeysPreserveStrictEquality(): void
    {
        $object = (object) ['id' => 1];
        $other = (object) ['id' => 1];
        $keys = [1, '1', null, false, ['id' => 1], $object, $other, null, ['id' => 1], $object];
        $source = Enumerable::from($keys);
        self::assertSame([1, '1', false, ['id' => 1], $object, $other], $source->exceptBy([null], fn ($x) => $x)->toArray());
        self::assertSame([1, $object], $source->intersectBy([1, $object], fn ($x) => $x)->toArray());
        self::assertSame([1, '1', null, false, ['id' => 1], $object, $other], $source->unionBy([], fn ($x) => $x)->toArray());
        self::assertSame([[1, 1], ['1', 1], [null, 2], [false, 1], [['id' => 1], 2], [$object, 2], [$other, 1]],
            $this->pairs($source->countBy(fn ($x) => $x)));
    }

    public function testAggregateCanStoreNullAndFalseValues(): void
    {
        self::assertSame([['a', null], ['b', null]], $this->pairs(Enumerable::from(['a', 'b', 'a'])
            ->aggregateBy(fn ($x) => $x, null, fn ($acc, $item) => null)));
        self::assertSame([['a', false]], $this->pairs(Enumerable::from(['a', 'a'])
            ->aggregateBy(fn ($x) => $x, false, fn ($acc, $item) => false)));
    }

    public function testAllOperatorsSupportCustomComparerAndHashCollisions(): void
    {
        $comparer = new class implements EqualityComparer {
            public function hash(mixed $value): string { return 'collision'; }
            public function equals(mixed $left, mixed $right): bool { return strtolower($left) === strtolower($right); }
        };
        $source = Enumerable::from(['B', 'A', 'b', 'C']);
        $key = fn ($x) => $x;
        self::assertSame(['B', 'C'], $source->exceptBy(['a'], $key, $comparer)->toArray());
        self::assertSame(['B', 'A'], $source->intersectBy(['a', 'b'], $key, $comparer)->toArray());
        self::assertSame(['B', 'A', 'C', 'D'], $source->unionBy(['a', 'D', 'd'], $key, $comparer)->toArray());
        self::assertSame([['B', 2], ['A', 1], ['C', 1]], $this->pairs($source->countBy($key, $comparer)));
        self::assertSame([['B', 'Bb'], ['A', 'A'], ['C', 'C']],
            $this->pairs($source->aggregateBy($key, '', fn ($acc, $x) => $acc.$x, $comparer)));
    }

    public function testAggregateDefersThenConsumesSourceBeforeFirstResultAndRebuilds(): void
    {
        $reads = $keyCalls = $accCalls = 0;
        $source = Enumerable::defer(static function () use (&$reads) {
            foreach ([1, 2, 1] as $value) { ++$reads; yield $value; }
        });
        $query = $source->aggregateBy(
            static function ($value) use (&$keyCalls) { ++$keyCalls; return $value; }, 0,
            static function ($acc, $value) use (&$accCalls) { ++$accCalls; return $acc + $value; },
        );
        self::assertSame(0, $reads + $keyCalls + $accCalls);
        self::assertSame(2, $query->first()->value);
        self::assertSame([3, 3, 3], [$reads, $keyCalls, $accCalls]);
        self::assertSame([[1, 2], [2, 2]], $this->pairs($query));
        self::assertSame([6, 6, 6], [$reads, $keyCalls, $accCalls]);
        $counts = $source->countBy(fn ($x) => $x);
        self::assertSame(6, $reads);
        self::assertSame(2, $counts->first()->value);
        self::assertSame(9, $reads);
    }

    public function testSetOperatorsDeferReadsAndSupportReplayableIterables(): void
    {
        foreach (['exceptBy', 'intersectBy', 'unionBy'] as $method) {
            $reads = 0;
            $source = Enumerable::defer(static function () use (&$reads) { ++$reads; yield 1; yield 2; });
            $second = Enumerable::defer(static function () use (&$reads) { ++$reads; yield 2; yield 3; });
            $query = $source->$method($second, fn ($x) => $x);
            self::assertSame(0, $reads);
            $expected = match ($method) { 'exceptBy' => [1], 'intersectBy' => [2], 'unionBy' => [1, 2, 3] };
            self::assertSame($expected, $query->toArray());
            self::assertSame(2, $reads);
            self::assertSame($expected, $query->toArray());
            self::assertSame(4, $reads);
        }
    }

    public function testUnionDoesNotReadSecondUntilNeeded(): void
    {
        $second = (static function () { throw new \RuntimeException('Second source read too soon'); yield; })();
        self::assertSame([1], Enumerable::from([1, 1])->unionBy($second, fn ($x) => $x)->take(1)->toArray());
    }

    public function testExceptAndIntersectBufferKeysButStreamFirstSource(): void
    {
        foreach (['exceptBy' => [9, 9], 'intersectBy' => [1, 1]] as $method => $keys) {
            $reads = 0;
            $second = (static function () use (&$reads, $keys) {
                foreach ($keys as $key) { ++$reads; yield $key; }
            })();
            $source = Enumerable::defer(static function () { yield 1; throw new \RuntimeException('Read too far'); });
            self::assertSame([1], $source->$method($second, fn ($x) => $x)->take(1)->toArray());
            self::assertSame(2, $reads);
        }
    }
}
