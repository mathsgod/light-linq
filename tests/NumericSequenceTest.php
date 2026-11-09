<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\Enumerable;
use PHPUnit\Framework\TestCase;

final class NumericSequenceTest extends TestCase
{
    public function testInclusiveBoundsStepsAndReplay(): void
    {
        $query = Enumerable::sequence(2, 10, 2);
        self::assertSame([2, 4, 6, 8, 10], $query->toArray());
        self::assertSame([2, 4, 6, 8, 10], $query->toArray());
        self::assertSame([10, 7, 4], Enumerable::sequence(10, 2, -3)->toArray());
        self::assertSame([1, 3, 5], Enumerable::sequence(1, 6, 2)->toArray());
        self::assertSame([5], Enumerable::sequence(5, 5, 0)->toArray());
        self::assertSame([5], Enumerable::sequence(5, 5, -1)->toArray());
        self::assertSame([0.0, 0.25, 0.5, 0.75, 1.0], Enumerable::sequence(0.0, 1.0, 0.25)->toArray());
        self::assertSame([1.0, 0.5, 0.0], Enumerable::sequence(1.0, 0.0, -0.5)->toArray());
    }

    public function testInfiniteSequencesAreLazyReusableAndComposable(): void
    {
        $query = Enumerable::infiniteSequence(10, 5)->take(4);
        self::assertSame([10, 15, 20, 25], $query->toArray());
        self::assertSame([10, 15, 20, 25], $query->toArray());
        self::assertSame([3, 1, -1], Enumerable::infiniteSequence(3, -2)->take(3)->toArray());
        self::assertSame([2, 2, 2], Enumerable::infiniteSequence(2, 0)->take(3)->toArray());
        self::assertSame([0.0, 0.5, 1.0], Enumerable::infiniteSequence(0.0, 0.5)->take(3)->toArray());
        self::assertSame([4, 8], Enumerable::infiniteSequence(1, 1)->where(fn ($n) => $n % 2 === 0)->select(fn ($n) => $n * 2)->take(2)->toArray());
        self::assertSame([0, 1, 2], Enumerable::sequence(0, PHP_INT_MAX, 1)->take(3)->toArray());
    }

    public function testInvalidArgumentsFailImmediately(): void
    {
        foreach ([[1, 2, 0], [1, 2, -1], [2, 1, 1], [NAN, 1, 1], [0, INF, 1], [0, 1, -INF]] as $args) {
            try {
                Enumerable::sequence(...$args);
                self::fail('Expected invalid arguments');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        foreach ([[NAN, 1], [0, INF]] as $args) {
            try {
                Enumerable::infiniteSequence(...$args);
                self::fail('Expected invalid arguments');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testFiniteSequenceStopsAtNumericLimitsAndStagnation(): void
    {
        self::assertSame([PHP_INT_MAX - 1, PHP_INT_MAX], Enumerable::sequence(PHP_INT_MAX - 1, PHP_INT_MAX, 1)->toArray());
        self::assertSame([PHP_INT_MIN + 1, PHP_INT_MIN], Enumerable::sequence(PHP_INT_MIN + 1, PHP_INT_MIN, -1)->toArray());
        self::assertSame([PHP_INT_MAX - 1], Enumerable::sequence(PHP_INT_MAX - 1, PHP_INT_MAX, 2)->toArray());
        self::assertSame([PHP_INT_MIN + 1], Enumerable::sequence(PHP_INT_MIN + 1, PHP_INT_MIN, -2)->toArray());
        self::assertSame([1.0e20], Enumerable::sequence(1.0e20, 2.0e20, 1.0)->toArray());
        self::assertSame([PHP_FLOAT_MAX], Enumerable::sequence(PHP_FLOAT_MAX, PHP_FLOAT_MAX, PHP_FLOAT_MAX)->toArray());
    }

    public function testTakeDoesNotComputeAnUnrequestedOverflowingValue(): void
    {
        self::assertSame([PHP_INT_MAX], Enumerable::infiniteSequence(PHP_INT_MAX, 1)->take(1)->toArray());
        self::assertSame([], Enumerable::infiniteSequence(PHP_INT_MAX, 1)->take(0)->toArray());
    }

    public function testInfiniteSequenceFailsInsteadOfPromotingOverflowOrRepeatingStagnantValues(): void
    {
        foreach ([[PHP_INT_MAX, 1], [PHP_INT_MIN, -1], [1.0e20, 1.0], [PHP_FLOAT_MAX, PHP_FLOAT_MAX]] as [$start, $step]) {
            try {
                Enumerable::infiniteSequence($start, $step)->take(2)->toArray();
                self::fail('Expected overflow');
            } catch (\OverflowException) {
                self::assertTrue(true);
            }
        }
    }
}
