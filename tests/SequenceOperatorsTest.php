<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\Enumerable;
use Light\Collections\Generic\EqualityComparer;
use PHPUnit\Framework\TestCase;

final class SequenceOperatorsTest extends TestCase
{
    public function testDefaultIfEmptyOnlySuppliesFallbackForEmptySequences(): void
    {
        self::assertSame([null], Enumerable::from([])->defaultIfEmpty()->toArray());
        self::assertSame([0], Enumerable::from([])->defaultIfEmpty(0)->toArray());
        self::assertSame([null, false, 0], Enumerable::from([null, false, 0])->defaultIfEmpty(99)->toArray());
        self::assertSame([2, 3], Enumerable::from(['a' => 2, 'b' => 3])->defaultIfEmpty(0)->toArray());
        self::assertSame([20], Enumerable::from([])->defaultIfEmpty(10)->select(fn ($x) => $x * 2)->toArray());
    }

    public function testDefaultIfEmptyIsDeferredStreamsAndReenumerates(): void
    {
        $reads = 0;
        $source = Enumerable::defer(static function () use (&$reads) {
            foreach ([null, false] as $value) { ++$reads; yield $value; }
        });
        $query = $source->defaultIfEmpty('fallback');
        self::assertSame(0, $reads);
        self::assertNull($query->first());
        self::assertSame(1, $reads);
        self::assertSame([null, false], $query->toArray());
        self::assertSame(3, $reads);
    }

    public function testElementAtUsesSequencePositionAndPreservesNullAndFalse(): void
    {
        $source = Enumerable::from(['a' => null, 10 => false, 'b' => 'Cat']);
        self::assertNull($source->elementAt(0));
        self::assertFalse($source->elementAt(1));
        self::assertSame('Cat', $source->elementAt(2));
        self::assertNull($source->elementAtOrDefault(0, 'missing'));
        self::assertFalse($source->elementAtOrDefault(1, 'missing'));
        self::assertSame('Cat', $source->elementAtOrDefault(2));
        self::assertSame('missing', $source->elementAtOrDefault(3, default: 'missing'));
        self::assertNull(Enumerable::from([])->elementAtOrDefault(0));
    }

    public function testElementAtRejectsNegativeAndOutOfRangeIndices(): void
    {
        foreach ([-1, 3] as $index) {
            try {
                Enumerable::from([1, 2, 3])->elementAt($index);
                self::fail('Expected an out-of-bounds exception.');
            } catch (\OutOfBoundsException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(\OutOfBoundsException::class);
        Enumerable::from([])->elementAt(0);
    }

    public function testNegativeDefaultIndexDoesNotReadSource(): void
    {
        $source = Enumerable::defer(static function () { throw new \RuntimeException('Unexpected read'); yield; });
        self::assertSame('missing', $source->elementAtOrDefault(-1, 'missing'));
        $this->expectException(\OutOfBoundsException::class);
        $source->elementAt(-1);
    }

    public function testElementAtStopsImmediatelyAtRequestedPosition(): void
    {
        $source = Enumerable::defer(static function () {
            yield 10;
            yield 20;
            throw new \RuntimeException('Read past requested position');
        });
        self::assertSame(20, $source->elementAt(1));
        self::assertSame(20, $source->elementAtOrDefault(1));
    }

    public function testZipPairsByPositionToTheShorterLengthAndComposes(): void
    {
        $names = Enumerable::from(['a' => 'Ada', 'b' => 'Bob', 'c' => 'Cat']);
        self::assertSame([['Ada', 90], ['Bob', 80]], $names->zip(
            ['first' => 90, 'second' => 80], fn ($name, $score) => [$name, $score],
        )->toArray());
        self::assertSame([11], Enumerable::from([1])->zip([10, 20], fn ($a, $b) => $a + $b)->toArray());
        self::assertSame([22], Enumerable::from([1, 2])->zip([10, 20], fn ($a, $b) => $a + $b)
            ->where(fn ($sum) => $sum > 20)->toArray());
        self::assertSame([], $names->zip([], fn () => throw new \RuntimeException('Unexpected selector'))->toArray());
    }

    public function testZipIsDeferredAndDoesNotReadAheadOfSelectedPairs(): void
    {
        $reads = $calls = 0;
        $source = Enumerable::defer(static function () use (&$reads) {
            ++$reads;
            yield null;
            throw new \RuntimeException('Read ahead');
        });
        $query = $source->zip($source, static function ($a, $b) use (&$calls) { ++$calls; return [$a, $b]; });
        self::assertSame(0, $reads);
        self::assertSame([[null, null]], $query->take(1)->toArray());
        self::assertSame(2, $reads);
        self::assertSame(1, $calls);
        self::assertSame([[null, null]], $query->take(1)->toArray());
        self::assertSame(4, $reads);
    }

    public function testZipWithEmptyOuterDoesNotReadSecond(): void
    {
        $second = (static function () { throw new \RuntimeException('Unexpected read'); yield; })();
        self::assertSame([], Enumerable::from([])->zip($second, fn ($a, $b) => [$a, $b])->toArray());
    }

    public function testZipDoesNotAdvanceSecondAfterOuterEnds(): void
    {
        $second = (static function () { yield 10; throw new \RuntimeException('Unexpected read'); })();
        self::assertSame([11], Enumerable::from([1])->zip($second, fn ($a, $b) => $a + $b)->toArray());
    }

    public function testSequenceEqualComparesValuesOrderLengthAndStrictTypes(): void
    {
        self::assertTrue(Enumerable::from([])->sequenceEqual([]));
        self::assertTrue(Enumerable::from(['a' => 1, 'b' => null, 'c' => false])->sequenceEqual([1, null, false]));
        self::assertFalse(Enumerable::from([1, 2])->sequenceEqual([2, 1]));
        self::assertFalse(Enumerable::from([1])->sequenceEqual(['1']));
        self::assertFalse(Enumerable::from([1])->sequenceEqual([1, 2]));
        self::assertFalse(Enumerable::from([1, 2])->sequenceEqual([1]));
        self::assertFalse(Enumerable::from([])->sequenceEqual([1]));
        self::assertFalse(Enumerable::from([1])->sequenceEqual([]));
        $object = (object) ['id' => 1];
        self::assertTrue(Enumerable::from([$object])->sequenceEqual([$object]));
        self::assertFalse(Enumerable::from([$object])->sequenceEqual([clone $object]));
        self::assertTrue(Enumerable::from([NAN])->sequenceEqual([NAN]));
    }

    public function testSequenceEqualSupportsComparerWithoutHashing(): void
    {
        $comparer = new class implements EqualityComparer {
            public function equals(mixed $left, mixed $right): bool { return strtolower($left) === strtolower($right); }
            public function hash(mixed $value): string { throw new \RuntimeException('No hashing needed'); }
        };
        self::assertTrue(Enumerable::from(['Ada', 'BOB'])->sequenceEqual(new \ArrayIterator(['ADA', 'Bob']), $comparer));
        self::assertFalse(Enumerable::from(['Ada'])->sequenceEqual(['Cat'], $comparer));
    }

    public function testSequenceEqualStopsAtFirstDifferenceWithoutReadingAhead(): void
    {
        $first = Enumerable::defer(static function () { yield 1; throw new \RuntimeException('Unexpected read'); });
        $second = (static function () { yield 2; throw new \RuntimeException('Unexpected read'); })();
        self::assertFalse($first->sequenceEqual($second));
    }
}
