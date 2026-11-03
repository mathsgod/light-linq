<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\Enumerable;
use PHPUnit\Framework\TestCase;

final class SkipLastTest extends TestCase
{
    public function testDropsTailAndPreservesOrder(): void
    {
        self::assertSame([1, 2, 3], Enumerable::from([1, 2, 3, 4, 5])->skipLast(2)->toArray());
        self::assertSame([1, 2, 3], Enumerable::from([1, 2, 3])->skipLast(0)->toArray());
        self::assertSame([], Enumerable::from([1, 2, 3])->skipLast(3)->toArray());
        self::assertSame([], Enumerable::from([1, 2, 3])->skipLast(5)->toArray());
        self::assertSame([], Enumerable::empty()->skipLast(0)->toArray());
        self::assertSame([], Enumerable::empty()->skipLast(2)->toArray());
        self::assertSame([], Enumerable::from([1])->skipLast(PHP_INT_MAX)->toArray());
    }

    public function testPreservesNullFalseAndObjectIdentity(): void
    {
        $object = (object) ['id' => 1];
        $source = Enumerable::from(['a' => null, 'b' => false, 'c' => $object, 'd' => 0]);
        self::assertSame([null, false, $object], $source->skipLast(1)->toArray());
    }

    public function testNegativeCountThrowsBeforeEnumerating(): void
    {
        $source = Enumerable::defer(static function () { throw new \RuntimeException('Unexpected read'); yield; });
        $this->expectException(\InvalidArgumentException::class);
        $source->skipLast(-1);
    }

    public function testDeferredExecutionAndBoundedReadAhead(): void
    {
        $reads = 0;
        $source = Enumerable::defer(static function () use (&$reads) {
            for ($n = 1; ; ++$n) {
                ++$reads;
                yield $n;
            }
        });
        $query = $source->skipLast(2);
        self::assertSame(0, $reads);
        self::assertSame([1, 2, 3], $query->take(3)->toArray());
        self::assertSame(5, $reads);
        self::assertSame([1], $query->take(1)->toArray());
        self::assertSame(8, $reads);
    }

    public function testZeroCountDoesNotReadAhead(): void
    {
        $source = Enumerable::defer(static function () {
            yield null;
            throw new \RuntimeException('Unexpected read ahead');
        });
        self::assertSame([null], $source->skipLast(0)->take(1)->toArray());
    }

    public function testRepeatedEnumerationRebuildsBufferAndComposes(): void
    {
        $query = Enumerable::range(1, 6)->skipLast(2);
        self::assertSame([1, 2, 3, 4], $query->toArray());
        self::assertSame([1, 2, 3, 4], $query->toArray());
        self::assertSame([20, 40], $query->where(fn ($n) => $n % 2 === 0)->select(fn ($n) => $n * 10)->toArray());
        self::assertSame([1, 2], $query->skipLast(2)->toArray());
    }
}
