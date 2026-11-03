<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\Enumerable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnumerableBoundaryTest extends TestCase
{
    public function testTakingZeroFromEitherEndDoesNotReadSource(): void
    {
        $source = Enumerable::defer(static function (): iterable {
            throw new \RuntimeException('Unexpected source read'); yield;
        });
        self::assertSame([], $source->take(0)->toArray());
        self::assertSame([], $source->takeLast(0)->toArray());
    }

    public static function invalidChunkSizes(): array
    {
        return ['zero' => [0], 'negative' => [-1]];
    }

    #[DataProvider('invalidChunkSizes')]
    public function testChunkRejectsInvalidSizesBeforeReadingSource(int $size): void
    {
        $source = Enumerable::defer(static function (): iterable {
            throw new \RuntimeException('Unexpected source read'); yield;
        });
        $this->expectException(\InvalidArgumentException::class);
        $source->chunk($size);
    }

    public function testAllIsFalseAtFirstFailureAndTrueForEmptySource(): void
    {
        $source = Enumerable::defer(static function (): iterable {
            yield 1; yield 0;
            throw new \RuntimeException('Read past failure');
        });
        self::assertFalse($source->all(fn ($value) => $value > 0));
        self::assertTrue(Enumerable::empty()->all(fn () => throw new \RuntimeException('Unexpected predicate')));
    }

    public function testAverageRejectsEmptySequence(): void
    {
        $this->expectException(\UnderflowException::class);
        Enumerable::empty()->average();
    }

    public function testUnseededAggregateUsesFirstElementAndInvokesAccumulatorOnlyForRemainingElements(): void
    {
        $calls = 0;
        $result = Enumerable::from([10, 3, 2])->aggregate(static function ($accumulator, $value) use (&$calls) {
            ++$calls;
            return $accumulator - $value;
        });
        self::assertSame(5, $result);
        self::assertSame(2, $calls);
        self::assertNull(Enumerable::from([null])->aggregate(fn () => throw new \RuntimeException('Unexpected accumulator')));
        self::assertNull(Enumerable::empty()->aggregate(fn () => throw new \RuntimeException('Unexpected accumulator'), null));
    }

    public function testUnseededAggregateRejectsEmptySequence(): void
    {
        $this->expectException(\UnderflowException::class);
        Enumerable::empty()->aggregate(fn ($sum, $value) => $sum + $value);
    }
}
