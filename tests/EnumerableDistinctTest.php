<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\Enumerable;
use PHPUnit\Framework\TestCase;

final class EnumerableDistinctTest extends TestCase
{
    public function testDistinctPreservesFirstOccurrenceOrderAndStrictTypes(): void
    {
        self::assertSame([3, 1, '1', null, false, 0, 1.0, true], Enumerable::from([
            3, 1, 3, '1', null, false, 0, 1.0, true, null, false, '1', 1,
        ])->distinct()->toArray());
        self::assertSame([], Enumerable::empty()->distinct()->toArray());
    }

    public function testKeySelectorKeepsOriginalFirstElementAndIgnoresIteratorKeys(): void
    {
        $source = Enumerable::defer(static function (): iterable {
            yield 'same' => ['id' => 2, 'name' => 'first'];
            yield 'same' => ['id' => 1, 'name' => 'second'];
            yield 'same' => ['id' => 2, 'name' => 'duplicate'];
        });
        self::assertSame([
            ['id' => 2, 'name' => 'first'], ['id' => 1, 'name' => 'second'],
        ], $source->distinct(fn ($row) => $row['id'])->toArray());
    }

    public function testDefaultComparerDeduplicatesNanAndSignedFloatZero(): void
    {
        $values = Enumerable::from([NAN, NAN, -0.0, 0.0, 0])->distinct()->toArray();
        self::assertCount(3, $values);
        self::assertNan($values[0]);
        self::assertSame(0.0, $values[1]);
        self::assertSame(0, $values[2]);
    }

    public function testArraysUseStrictEqualityAndNestedObjectsUseIdentityWithoutSerialization(): void
    {
        $object = (object) ['id' => 1];
        $other = (object) ['id' => 1];
        $closure = static fn () => 1;
        $values = [
            ['id' => 1], ['id' => 1], ['id' => '1'],
            ['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1],
            $object, $object, $other,
            [$object, $closure], [$object, $closure], [$other, $closure],
        ];
        self::assertSame([
            ['id' => 1], ['id' => '1'], ['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1],
            $object, $other, [$object, $closure], [$other, $closure],
        ], Enumerable::from($values)->distinct()->toArray());
    }

    public function testHashCollisionsDoNotMergeDifferentKeys(): void
    {
        // DefaultEqualityComparer bounds array hashing at depth 32. These keys
        // share a truncated hash but still require a full equality check.
        $first = 1;
        $second = 2;
        for ($depth = 0; $depth < 34; ++$depth) {
            $first = [$first];
            $second = [$second];
        }
        self::assertSame([$first, $second], Enumerable::from([$first, $second, $first])->distinct()->toArray());
    }

    public function testDistinctDefersStreamsAndRebuildsSeenKeysForEachEnumeration(): void
    {
        $reads = $calls = 0;
        $source = Enumerable::defer(static function () use (&$reads): iterable {
            foreach ([2, 2, 1] as $value) { ++$reads; yield $value; }
        });
        $query = $source->distinct(static function ($value) use (&$calls) { ++$calls; return $value; });
        self::assertSame([0, 0], [$reads, $calls]);
        self::assertSame(2, $query->first());
        self::assertSame([1, 1], [$reads, $calls]);
        self::assertSame([2, 1], $query->toArray());
        self::assertSame([4, 4], [$reads, $calls]);
        self::assertSame([2, 1], $query->toArray());
        self::assertSame([7, 7], [$reads, $calls]);
    }

    public function testTakeDoesNotReadBeyondRequestedUniqueValues(): void
    {
        $source = Enumerable::defer(static function (): iterable {
            yield 2; yield 2; yield 1;
            throw new \RuntimeException('Read too far');
        });
        self::assertSame([2, 1], $source->distinct()->take(2)->toArray());
    }

    public function testKeySelectorFailureIsPropagatedDuringEnumeration(): void
    {
        $failure = new \RuntimeException('Key selector failed');
        $query = Enumerable::from([1])->distinct(fn () => throw $failure);
        try {
            $query->toArray();
            self::fail('Expected key selector exception');
        } catch (\RuntimeException $error) {
            self::assertSame($failure, $error);
        }
    }
}
