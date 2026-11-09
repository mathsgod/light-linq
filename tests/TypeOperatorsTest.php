<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\Enumerable;
use PHPUnit\Framework\TestCase;

final class TypeOperatorsTest extends TestCase
{
    public function testOfTypeFiltersStrictlyAndDropsNull(): void
    {
        $source = Enumerable::from([null, 1, '1', 1.0, true, [], new \stdClass()]);
        foreach (['int' => [1], 'string' => ['1'], 'float' => [1.0], 'bool' => [true], 'array' => [[]]] as $type => $expected) {
            self::assertSame($expected, $source->ofType($type)->toArray());
        }
        self::assertSame([], Enumerable::empty()->ofType('int')->toArray());
    }

    public function testClassAndInterfaceMatchingPreservesObjectIdentity(): void
    {
        $first = new \ArrayIterator([1]);
        $child = new class extends \ArrayIterator {};
        $source = Enumerable::from([null, $first, new \stdClass(), $child]);
        self::assertSame([$first, $child], $source->ofType(\ArrayIterator::class)->toArray());
        self::assertSame([$first, $child], $source->ofType(\Traversable::class)->cast(\Traversable::class)->toArray());
    }

    public function testAdditionalPhpTypes(): void
    {
        $iterator = new \ArrayIterator();
        $callback = static fn () => 1;
        $resource = fopen('php://memory', 'r+');
        try {
            $source = Enumerable::from([null, [], $iterator, $callback, $resource]);
            self::assertSame([[], $iterator], $source->ofType('iterable')->toArray());
            self::assertSame([$iterator, $callback], $source->ofType('object')->toArray());
            self::assertSame([$callback], $source->ofType('callable')->toArray());
            self::assertSame([$resource], $source->ofType('resource')->toArray());
        } finally {
            fclose($resource);
        }
    }

    public function testCastDefersFailureWithoutConvertingValues(): void
    {
        $query = Enumerable::from([1, '2'])->cast('int');
        self::assertSame(1, $query->first());
        $this->expectException(\UnexpectedValueException::class);
        $query->toArray();
    }

    public function testCastRejectsNull(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        Enumerable::from([null])->cast(\stdClass::class)->toArray();
    }

    public function testBothOperatorsDeferAndReplaySource(): void
    {
        foreach (['cast', 'ofType'] as $method) {
            $reads = 0;
            $source = Enumerable::defer(static function () use (&$reads): iterable {
                ++$reads;
                yield from [1, 2];
            });
            $query = $source->$method('int');
            self::assertSame(0, $reads);
            self::assertSame([1, 2], $query->toArray());
            self::assertSame([1, 2], $query->toArray());
            self::assertSame(2, $reads);
        }
        self::assertSame([], Enumerable::empty()->cast('int')->toArray());
    }

    public function testInvalidTypeIsRejectedBeforeEnumerationEvenForEmptySource(): void
    {
        foreach (['cast', 'ofType'] as $method) {
            foreach (['', 'NoSuchElementClass', 'int|string', '?int', 'mixed', 'null'] as $type) {
                try {
                    Enumerable::empty()->$method($type);
                    self::fail('Expected invalid type rejection');
                } catch (\InvalidArgumentException) {
                    self::assertTrue(true);
                }
            }
        }
    }
}
