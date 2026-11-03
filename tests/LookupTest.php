<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Collections\Generic\EqualityComparer;
use Light\Linq\Enumerable;
use Light\Linq\Grouping;
use Light\Linq\IEnumerable;
use Light\Linq\Lookup;
use PHPUnit\Framework\TestCase;

final class LookupTest extends TestCase
{
    public function testLookupPreservesFirstKeyAndElementOrderAndCountsGroups(): void
    {
        $rows = [['id' => 2, 'n' => 'first'], ['id' => 1, 'n' => 'second'], ['id' => 2, 'n' => 'third']];
        $lookup = Enumerable::from($rows)->toLookup(fn ($row) => $row['id']);
        self::assertInstanceOf(Lookup::class, $lookup);
        self::assertCount(2, $lookup);
        self::assertTrue($lookup->contains(2));
        self::assertSame([$rows[0], $rows[2]], $lookup->get(2)->toArray());
        self::assertSame([$rows[1]], $lookup->get(1)->toArray());
        $groups = iterator_to_array($lookup, false);
        self::assertContainsOnlyInstancesOf(Grouping::class, $groups);
        self::assertSame([2, 1], array_map(fn ($group) => $group->key, $groups));
        self::assertSame([$rows[0], $rows[2]], $groups[0]->items);
        self::assertSame($groups[0]->items, iterator_to_array($groups[0], false));
        self::assertCount(2, $groups[0]);
    }

    public function testElementSelectorSupportsNullFalseAndComposableResults(): void
    {
        $lookup = Enumerable::from([
            ['id' => 1, 'value' => null], ['id' => 1, 'value' => false], ['id' => 2, 'value' => 10],
        ])->toLookup(fn ($row) => $row['id'], fn ($row) => $row['value']);
        self::assertTrue($lookup->contains(1));
        self::assertSame([null, false], $lookup->get(1)->toArray());
        self::assertSame([20], $lookup->get(2)->select(fn ($value) => $value * 2)->toArray());
        self::assertSame([2], Enumerable::from($lookup)->where(fn ($group) => count($group) === 1)
            ->select(fn ($group) => $group->key)->toArray());
    }

    public function testMissingKeysReturnReusableEmptySequencesWithoutCreatingGroups(): void
    {
        $lookup = Enumerable::from([1, 1])->toLookup(fn ($value) => $value);
        $missing = $lookup->get(9);
        self::assertInstanceOf(IEnumerable::class, $missing);
        self::assertSame([], $missing->toArray());
        self::assertSame([], $missing->toArray());
        self::assertFalse($lookup->contains(9));
        self::assertCount(1, $lookup);
        self::assertNull($lookup->get(null)->firstOrDefault());
        self::assertCount(1, $lookup);
    }

    public function testNullKeysAndElementsAreSupportedWithStrictScalarKeys(): void
    {
        $values = [null, 1, '1', false, 0, 0.0, null];
        $lookup = Enumerable::from($values)->toLookup(fn ($value) => $value);
        self::assertCount(6, $lookup);
        self::assertTrue($lookup->contains(null));
        self::assertSame([null, null], $lookup->get(null)->toArray());
        foreach ([1, '1', false, 0, 0.0] as $value) {
            self::assertSame([$value], $lookup->get($value)->toArray());
        }
    }

    public function testArrayAndObjectKeysUseStrictEqualityAndIdentity(): void
    {
        $one = (object) ['id' => 1];
        $two = (object) ['id' => 1];
        $closure = static fn () => 1;
        $keys = [['id' => 1], ['id' => 1], ['id' => '1'], $one, $two, $one, [$one, $closure], [$one, $closure]];
        $lookup = Enumerable::from($keys)->toLookup(fn ($value) => $value);
        self::assertCount(5, $lookup);
        self::assertSame([['id' => 1], ['id' => 1]], $lookup->get(['id' => 1])->toArray());
        self::assertSame([$one, $one], $lookup->get($one)->toArray());
        self::assertSame([$two], $lookup->get($two)->toArray());
        self::assertFalse($lookup->contains((object) ['id' => 1]));
        self::assertSame([[$one, $closure], [$one, $closure]], $lookup->get([$one, $closure])->toArray());
    }

    public function testNanAndSignedZeroFollowDefaultComparer(): void
    {
        $lookup = Enumerable::from([NAN, NAN, -0.0, 0.0])->toLookup(fn ($value) => $value);
        self::assertCount(2, $lookup);
        self::assertTrue($lookup->contains(NAN));
        self::assertCount(2, $lookup->get(NAN));
        self::assertCount(2, $lookup->get(0.0));
    }

    public function testCustomComparerHandlesCollisionsAndRetainsFirstKeyRepresentation(): void
    {
        $comparer = new class implements EqualityComparer {
            public function hash(mixed $value): string { return 'collision'; }
            public function equals(mixed $left, mixed $right): bool { return strtolower($left) === strtolower($right); }
        };
        $lookup = Enumerable::from(['B', 'A', 'b'])->toLookup(fn ($value) => $value, comparer: $comparer);
        self::assertCount(2, $lookup);
        self::assertSame(['B', 'b'], $lookup->get('b')->toArray());
        self::assertSame(['A'], $lookup->get('a')->toArray());
        self::assertTrue($lookup->contains('a'));
        self::assertFalse($lookup->contains('c'));
        self::assertSame([], $lookup->get('c')->toArray());
        self::assertSame(['B', 'A'], array_map(fn ($group) => $group->key, iterator_to_array($lookup, false)));
        $projected = Enumerable::from(['A', 'a'])->toLookup(fn ($value) => $value, fn ($value) => $value.'!', $comparer);
        self::assertSame(['A!', 'a!'], $projected->get('a')->toArray());
    }

    public function testNullKeysParticipateInCustomComparerInsteadOfBeingSpecialCased(): void
    {
        $comparer = new class implements EqualityComparer {
            public function hash(mixed $value): string { return strtolower($value ?? ''); }
            public function equals(mixed $left, mixed $right): bool { return strtolower($left ?? '') === strtolower($right ?? ''); }
        };
        $lookup = Enumerable::from([null, '', 'A', 'a'])->toLookup(fn ($value) => $value, comparer: $comparer);
        self::assertCount(2, $lookup);
        self::assertSame([null, ''], $lookup->get('')->toArray());
        self::assertSame([null, ''], $lookup->get(null)->toArray());
        self::assertSame([null, 'A'], array_map(fn ($group) => $group->key, iterator_to_array($lookup, false)));
    }

    public function testEmptyLookupDoesNotInvokeSelectors(): void
    {
        $bad = fn () => throw new \RuntimeException('Unexpected selector');
        $lookup = Enumerable::empty()->toLookup($bad, $bad);
        self::assertCount(0, $lookup);
        self::assertFalse($lookup->contains(null));
        self::assertSame([], $lookup->get(null)->toArray());
        self::assertSame([], iterator_to_array($lookup, false));
    }

    public function testConstructionConsumesSourceOnceImmediatelyAndNeverReexecutesSelectors(): void
    {
        $reads = $keys = $elements = 0;
        $source = (static function () use (&$reads): iterable {
            foreach ([1, 2, 1] as $value) { ++$reads; yield 'same' => $value; }
        })();
        $lookup = Enumerable::from($source)->toLookup(
            static function ($value) use (&$keys) { ++$keys; return $value; },
            static function ($value) use (&$elements) { ++$elements; return $value * 10; },
        );
        self::assertSame([3, 3, 3], [$reads, $keys, $elements]);
        $matches = $lookup->get(1);
        self::assertSame([10, 10], $matches->toArray());
        self::assertSame([10, 10], $matches->toArray());
        self::assertSame(iterator_to_array($lookup, false), iterator_to_array($lookup, false));
        self::assertTrue($lookup->contains(1));
        self::assertCount(2, $lookup);
        self::assertSame([3, 3, 3], [$reads, $keys, $elements]);
    }

    public function testConstructionCreatesIndependentReadOnlySnapshotWithSharedObjectReferences(): void
    {
        $object = (object) ['name' => 'Ada'];
        $source = [['key' => 1, 'value' => $object]];
        $lookup = Enumerable::from($source)->toLookup(fn ($row) => $row['key'], fn ($row) => $row['value']);
        $source[] = ['key' => 2, 'value' => new \stdClass()];
        self::assertCount(1, $lookup);
        $list = $lookup->get(1)->toList();
        $list->clear();
        self::assertSame([$object], $lookup->get(1)->toArray());
        $object->name = 'Alice';
        self::assertSame('Alice', $lookup->get(1)->first()->name);
        $group = iterator_to_array($lookup, false)[0];
        $this->expectException(\Error::class);
        $group->items[] = 'mutation';
    }

    public function testConstructorCanAlsoBeUsedDirectly(): void
    {
        $lookup = new Lookup([1, 3, 2], fn ($value) => $value % 2, fn ($value) => $value * 10);
        self::assertSame([10, 30], $lookup->get(1)->toArray());
        self::assertSame([20], $lookup->get(0)->toArray());
    }

    public function testConstructionPropagatesSourceSelectorAndComparerExceptions(): void
    {
        foreach (['source', 'key', 'element', 'hash', 'equals'] as $step) {
            $failure = new \RuntimeException($step);
            $bad = fn () => throw $failure;
            $source = $step === 'source'
                ? Enumerable::defer(static function () use ($failure): iterable { throw $failure; yield; })
                : Enumerable::from([1, 1]);
            $comparer = new class($step, $failure) implements EqualityComparer {
                public function __construct(private string $step, private \RuntimeException $failure) {}
                public function hash(mixed $value): string {
                    if ($this->step === 'hash') throw $this->failure;
                    return 'collision';
                }
                public function equals(mixed $left, mixed $right): bool {
                    if ($this->step === 'equals') throw $this->failure;
                    return $left === $right;
                }
            };
            try {
                $source->toLookup($step === 'key' ? $bad : fn ($x) => $x,
                    $step === 'element' ? $bad : null, $comparer);
                self::fail('Expected construction failure');
            } catch (\RuntimeException $error) {
                self::assertSame($failure, $error);
            }
        }
    }
}
