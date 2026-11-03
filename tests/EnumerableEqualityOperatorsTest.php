<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Collections\Generic\EqualityComparer;
use Light\Linq\Enumerable;
use Light\Linq\InMemoryQueryProvider;
use Light\Linq\Queryable;
use PHPUnit\Framework\TestCase;

final class EnumerableEqualityOperatorsTest extends TestCase
{
    public function testContainsUsesDefaultComparerForNanStrictTypesAndObjectIdentity(): void
    {
        $object = (object) ['id' => 1];
        $values = Enumerable::from([1, null, $object, ['id' => 1], NAN, -0.0]);
        self::assertTrue($values->contains(1));
        self::assertFalse($values->contains('1'));
        self::assertTrue($values->contains(null));
        self::assertFalse($values->contains(false));
        self::assertTrue($values->contains($object));
        self::assertFalse($values->contains((object) ['id' => 1]));
        self::assertTrue($values->contains(['id' => 1]));
        self::assertFalse($values->contains(['id' => '1']));
        self::assertTrue($values->contains(NAN));
        self::assertTrue($values->contains(0.0));
        self::assertFalse($values->contains(0));
    }

    public function testContainsCustomComparerDoesNotRequireHashingAndStopsAtMatch(): void
    {
        $comparisons = 0;
        $comparer = new class($comparisons) implements EqualityComparer {
            public function __construct(private int &$comparisons) {}
            public function hash(mixed $value): string { throw new \RuntimeException('No hashing needed'); }
            public function equals(mixed $left, mixed $right): bool {
                ++$this->comparisons;
                return strcasecmp($left, $right) === 0;
            }
        };
        $source = Enumerable::defer(static function (): iterable {
            yield 'Bob'; yield 'Ada';
            throw new \RuntimeException('Read past match');
        });
        self::assertTrue($source->contains('ADA', comparer: $comparer));
        self::assertSame(2, $comparisons);
        self::assertFalse(Enumerable::from(['Bob'])->contains('Cat', $comparer));
    }

    public function testContainsEmptySourceDoesNotInvokeComparer(): void
    {
        $comparer = new class implements EqualityComparer {
            public function hash(mixed $value): string { throw new \RuntimeException('Unexpected hash'); }
            public function equals(mixed $left, mixed $right): bool { throw new \RuntimeException('Unexpected comparison'); }
        };
        self::assertFalse(Enumerable::empty()->contains(null, $comparer));
    }

    public function testContainsPropagatesSourceAndComparerFailures(): void
    {
        $failure = new \RuntimeException('Comparison failed');
        $comparer = new class($failure) implements EqualityComparer {
            public function __construct(private \RuntimeException $failure) {}
            public function hash(mixed $value): string { return 'hash'; }
            public function equals(mixed $left, mixed $right): bool { throw $this->failure; }
        };
        try {
            Enumerable::from([1])->contains(1, $comparer);
            self::fail('Expected comparison failure');
        } catch (\RuntimeException $error) {
            self::assertSame($failure, $error);
        }
        $source = Enumerable::defer(static function () use ($failure): iterable { throw $failure; yield; });
        try {
            $source->contains(1);
            self::fail('Expected source failure');
        } catch (\RuntimeException $error) {
            self::assertSame($failure, $error);
        }
    }

    public function testDistinctByKeepsFirstRowsAndMatchesExistingDistinctKeySelector(): void
    {
        $source = Enumerable::from([
            ['id' => 2, 'name' => 'Ada'], ['id' => 1, 'name' => 'Bob'], ['id' => 2, 'name' => 'Cat'],
        ]);
        $key = fn ($row) => $row['id'];
        self::assertSame([
            ['id' => 2, 'name' => 'Ada'], ['id' => 1, 'name' => 'Bob'],
        ], $source->distinctBy($key)->toArray());
        self::assertSame($source->distinct($key)->toArray(), $source->distinctBy($key)->toArray());
        self::assertSame(['Ada', 'Bob'], $source->distinctBy($key)->select(fn ($row) => $row['name'])->toArray());
    }

    public function testDistinctByCustomKeyComparerHandlesCollisionsAndPreservesOriginalRows(): void
    {
        $comparer = new class implements EqualityComparer {
            public function hash(mixed $value): string { return 'collision'; }
            public function equals(mixed $left, mixed $right): bool { return strcasecmp($left, $right) === 0; }
        };
        $source = Enumerable::from([
            ['city' => 'HK', 'id' => 1], ['city' => 'Tokyo', 'id' => 2],
            ['city' => 'hk', 'id' => 3], ['city' => 'Paris', 'id' => 4],
        ]);
        self::assertSame([1, 2, 4], $source->distinctBy(fn ($row) => $row['city'], comparer: $comparer)
            ->select(fn ($row) => $row['id'])->toArray());
    }

    public function testDistinctBySupportsStrictNullArrayObjectAndNanKeys(): void
    {
        $object = (object) ['id' => 1];
        $other = (object) ['id' => 1];
        $keys = [null, null, 1, '1', ['id' => 1], ['id' => 1], $object, $object, $other, NAN, NAN];
        $rows = array_map(fn ($key, $id) => ['key' => $key, 'id' => $id], $keys, array_keys($keys));
        self::assertSame([0, 2, 3, 4, 6, 8, 9], Enumerable::from($rows)
            ->distinctBy(fn ($row) => $row['key'])->select(fn ($row) => $row['id'])->toArray());
    }

    public function testDistinctByDefersReadsAndRebuildsLookupForEachEnumeration(): void
    {
        $reads = $calls = 0;
        $source = Enumerable::defer(static function () use (&$reads): iterable {
            foreach ([1, 1, 2] as $value) { ++$reads; yield 'same' => $value; }
        });
        $query = $source->distinctBy(static function ($value) use (&$calls) { ++$calls; return $value; });
        self::assertSame([0, 0], [$reads, $calls]);
        self::assertSame(1, $query->first());
        self::assertSame([1, 1], [$reads, $calls]);
        self::assertSame([1, 2], $query->toArray());
        self::assertSame([4, 4], [$reads, $calls]);
        self::assertSame([1, 2], $query->toArray());
        self::assertSame([7, 7], [$reads, $calls]);
        self::assertSame([], Enumerable::empty()->distinctBy(fn () => throw new \RuntimeException('Unexpected selector'))->toArray());
    }

    public function testDistinctByTakeStopsWithoutReadingAheadAndPropagatesSelectorFailure(): void
    {
        $source = Enumerable::defer(static function (): iterable {
            yield 1; yield 1; yield 2;
            throw new \RuntimeException('Read too far');
        });
        self::assertSame([1, 2], $source->distinctBy(fn ($x) => $x)->take(2)->toArray());
        $failure = new \RuntimeException('Key failed');
        $query = Enumerable::from([1])->distinctBy(fn () => throw $failure);
        try {
            $query->toArray();
            self::fail('Expected selector failure');
        } catch (\RuntimeException $error) {
            self::assertSame($failure, $error);
        }
    }

    public function testQueryableContainsRetainsExistingProviderComparisonRules(): void
    {
        $provider = new InMemoryQueryProvider(['values' => [1, null, NAN]]);
        $query = Queryable::from($provider, 'values');
        self::assertTrue($query->contains(1));
        self::assertTrue($query->contains(null));
        self::assertFalse($query->contains('1'));
        self::assertFalse($query->contains(NAN));
        self::assertTrue($query->asEnumerable()->contains(NAN));
    }
}
