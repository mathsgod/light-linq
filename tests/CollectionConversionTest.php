<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Collections\Generic\Dictionary;
use Light\Collections\Generic\EqualityComparer;
use Light\Collections\Generic\GenericList;
use Light\Collections\Generic\HashSet;
use Light\Linq\Enumerable;
use Light\Linq\Expr;
use Light\Linq\InMemoryQueryProvider;
use Light\Linq\Queryable;
use PHPUnit\Framework\TestCase;

final class CollectionConversionTest extends TestCase
{
    public function testListPreservesOrderDuplicatesAndNullAndDiscardsIteratorKeys(): void
    {
        $source = Enumerable::defer(static function (): iterable {
            yield 'same' => 3;
            yield 'same' => null;
            yield 'other' => 3;
        });
        $list = $source->toList();
        self::assertInstanceOf(GenericList::class, $list);
        self::assertSame([3, null, 3], $list->toArray());
        $list->add(4);
        self::assertSame([3, null, 3], $source->toList()->toArray());
    }

    public function testHashSetUsesStrictEqualityAndObjectIdentity(): void
    {
        $object = (object) ['id' => 1];
        $other = (object) ['id' => 1];
        $set = Enumerable::from([1, '1', null, null, $object, $object, $other])->toHashSet();
        self::assertInstanceOf(HashSet::class, $set);
        self::assertCount(5, $set);
        foreach ([1, '1', null, $object, $other] as $value) {
            self::assertTrue($set->contains($value));
        }
    }

    public function testDictionaryDefaultsToOriginalElementsAndPreservesKeyTypes(): void
    {
        $object = (object) ['id' => 1];
        $rows = [['key' => 1], ['key' => '1'], ['key' => $object]];
        $dictionary = Enumerable::from($rows)->toDictionary(fn ($row) => $row['key']);
        self::assertInstanceOf(Dictionary::class, $dictionary);
        self::assertCount(3, $dictionary);
        foreach ($rows as $row) {
            self::assertSame($row, $dictionary->get($row['key']));
        }
    }

    public function testDictionaryProjectsValuesIncludingNullAndFalse(): void
    {
        $dictionary = Enumerable::from([
            ['id' => 'a', 'value' => null],
            ['id' => 'b', 'value' => false],
        ])->toDictionary(fn ($row) => $row['id'], fn ($row) => $row['value']);
        self::assertTrue($dictionary->containsKey('a'));
        self::assertNull($dictionary->get('a'));
        self::assertFalse($dictionary->get('b'));
    }

    public function testDictionaryRejectsDuplicateKeysInsteadOfOverwriting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Enumerable::from([['id' => 1], ['id' => 1]])->toDictionary(fn ($row) => $row['id']);
    }

    public function testDictionaryRejectsNullKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Enumerable::from([null])->toDictionary(fn ($value) => $value);
    }

    public function testCustomComparerHandlesHashCollisionsForBothCollections(): void
    {
        $comparer = $this->caseInsensitiveComparer();
        $source = Enumerable::from(['Ada', 'Bob', 'ADA']);
        $set = $source->toHashSet($comparer);
        self::assertCount(2, $set);
        self::assertTrue($set->contains('ada'));
        self::assertTrue($set->contains('BOB'));
        $dictionary = Enumerable::from(['Ada', 'Bob'])->toDictionary(
            fn ($value) => $value,
            fn ($value) => strlen($value),
            $comparer,
        );
        self::assertSame(3, $dictionary->get('ADA'));
        self::assertSame(3, $dictionary->get('bob'));
        $originals = Enumerable::from(['Ada'])->toDictionary(fn ($value) => $value, comparer: $comparer);
        self::assertSame('Ada', $originals->get('ADA'));
    }

    public function testDictionaryDetectsDuplicatesUsingCustomComparer(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Enumerable::from(['Ada', 'ADA'])->toDictionary(fn ($value) => $value, comparer: $this->caseInsensitiveComparer());
    }

    public function testEmptyConversionsDoNotInvokeSelectors(): void
    {
        $unexpected = fn () => throw new \RuntimeException('Unexpected selector');
        self::assertCount(0, Enumerable::empty()->toList());
        self::assertCount(0, Enumerable::empty()->toHashSet());
        self::assertCount(0, Enumerable::empty()->toDictionary($unexpected, $unexpected));
    }

    public function testConversionsConsumeSourceOnceImmediatelyAndReturnIndependentSnapshots(): void
    {
        foreach (['toList', 'toHashSet', 'toDictionary'] as $method) {
            $reads = 0;
            $source = Enumerable::defer(static function () use (&$reads): iterable {
                foreach ([1, 2] as $value) {
                    ++$reads;
                    yield $value;
                }
            });
            $args = $method === 'toDictionary' ? [fn ($value) => $value] : [];
            $first = $source->$method(...$args);
            self::assertSame(2, $reads);
            self::assertCount(2, $first);
            self::assertCount(2, $first);
            self::assertSame(2, $reads);
            $second = $source->$method(...$args);
            self::assertSame(4, $reads);
            $first->clear();
            self::assertCount(2, $second);
        }
    }

    public function testConversionsPropagateSourceAndSelectorFailures(): void
    {
        foreach (['toList', 'toHashSet', 'toDictionary'] as $method) {
            $failure = new \RuntimeException('Source failed');
            $source = Enumerable::defer(static function () use ($failure): iterable {
                yield 1;
                throw $failure;
            });
            try {
                $method === 'toDictionary' ? $source->toDictionary(fn ($value) => $value) : $source->$method();
                self::fail('Expected source failure');
            } catch (\RuntimeException $error) {
                self::assertSame($failure, $error);
            }
        }
        foreach (['key', 'value'] as $selector) {
            $failure = new \RuntimeException('Selector failed');
            $bad = fn ($value) => throw $failure;
            try {
                Enumerable::from([1])->toDictionary($selector === 'key' ? $bad : fn ($value) => $value, $selector === 'value' ? $bad : null);
                self::fail('Expected selector failure');
            } catch (\RuntimeException $error) {
                self::assertSame($failure, $error);
            }
        }
    }

    public function testConversionsMaterializeComposedQueriesAndProviderResults(): void
    {
        $query = Enumerable::from([3, 1, 2, 2])
            ->where(fn ($value) => $value > 1)
            ->orderBy(fn ($value) => $value)
            ->select(fn ($value) => $value * 10);
        self::assertSame([20, 20, 30], $query->toList()->toArray());
        self::assertTrue($query->toHashSet()->setEquals([20, 30]));
        self::assertSame(40, $query->distinct()->toDictionary(fn ($value) => $value, fn ($value) => $value * 2)->get(20));

        $provider = new InMemoryQueryProvider(['users' => [['id' => 1, 'name' => 'Ada']]]);
        $users = Queryable::from($provider, 'users')->where(Expr::eq(Expr::field('id'), 1))->asEnumerable();
        self::assertSame([['id' => 1, 'name' => 'Ada']], $users->toList()->toArray());
        self::assertCount(1, $users->toHashSet());
        self::assertSame('Ada', $users->toDictionary(fn ($row) => $row['id'], fn ($row) => $row['name'])->get(1));
    }

    private function caseInsensitiveComparer(): EqualityComparer
    {
        return new class implements EqualityComparer {
            public function hash(mixed $value): string { return 'collision'; }
            public function equals(mixed $left, mixed $right): bool { return strtolower($left) === strtolower($right); }
        };
    }
}
