<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\Enumerable;
use Light\Linq\IEnumerable;
use PHPUnit\Framework\TestCase;

final class SequenceCreationTest extends TestCase
{
    public function testEmptyReturnsReusableComposableSequence(): void
    {
        $source = Enumerable::empty();
        self::assertInstanceOf(IEnumerable::class, $source);
        self::assertSame([], $source->toArray());
        self::assertSame([], $source->toArray());
        self::assertSame(0, $source->count());
        self::assertFalse($source->any());
        self::assertSame(['fallback'], $source->defaultIfEmpty('fallback')->toArray());
    }

    public function testRangeUsesCountRatherThanEndAndSupportsNegativeStarts(): void
    {
        self::assertSame([5, 6, 7], Enumerable::range(5, 3)->toArray());
        self::assertSame([-2, -1, 0, 1], Enumerable::range(-2, 4)->toArray());
        self::assertSame([0], Enumerable::range(0, 1)->toArray());
        self::assertSame([], Enumerable::range(10, 0)->toArray());
    }

    public function testRangeIsReusableAndComposes(): void
    {
        $source = Enumerable::range(1, 5);
        self::assertSame(15, $source->sum());
        self::assertSame([20, 40], $source->where(fn ($n) => $n % 2 === 0)
            ->select(fn ($n) => $n * 10)->toArray());
        self::assertSame([1, 2, 3, 4, 5], $source->toArray());
    }

    public function testRangeRejectsNegativeCountImmediately(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Enumerable::range(0, -1);
    }

    public function testRangeHandlesIntegerBoundariesWithoutFloatConversion(): void
    {
        self::assertSame([PHP_INT_MAX - 1, PHP_INT_MAX], Enumerable::range(PHP_INT_MAX - 1, 2)->toArray());
        self::assertSame([PHP_INT_MIN, PHP_INT_MIN + 1], Enumerable::range(PHP_INT_MIN, 2)->toArray());
        self::assertSame([PHP_INT_MAX], Enumerable::range(PHP_INT_MAX, 1)->toArray());
        self::assertSame([], Enumerable::range(PHP_INT_MAX, 0)->toArray());
        self::assertSame([PHP_INT_MIN, PHP_INT_MIN + 1], Enumerable::range(PHP_INT_MIN, PHP_INT_MAX)->take(2)->toArray());
    }

    public function testRangeRejectsOverflowImmediately(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Enumerable::range(PHP_INT_MAX, 2);
    }

    public function testLargeSequencesAreGeneratedLazily(): void
    {
        self::assertSame([0, 1, 2], Enumerable::range(0, PHP_INT_MAX)->take(3)->toArray());
        self::assertSame(['A', 'A', 'A'], Enumerable::repeat('A', PHP_INT_MAX)->take(3)->toArray());
    }

    public function testRepeatSupportsMixedValuesAndZeroCount(): void
    {
        self::assertSame(['A', 'A', 'A'], Enumerable::repeat('A', 3)->toArray());
        self::assertSame([null, null], Enumerable::repeat(null, 2)->toArray());
        self::assertSame([false, false], Enumerable::repeat(false, 2)->toArray());
        self::assertSame([['id' => 1], ['id' => 1]], Enumerable::repeat(['id' => 1], 2)->toArray());
        self::assertSame([], Enumerable::repeat('A', 0)->toArray());
    }

    public function testRepeatReusesObjectIdentityAndReflectsMutations(): void
    {
        $object = (object) ['name' => 'Ada'];
        $source = Enumerable::repeat($object, 3);
        $items = $source->toArray();
        self::assertSame([$object, $object, $object], $items);
        $items[0]->name = 'Bob';
        self::assertSame('Bob', $items[2]->name);
        self::assertSame([$object, $object, $object], $source->toArray());
    }

    public function testRepeatRejectsNegativeCountImmediately(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Enumerable::repeat('A', -1);
    }
}
