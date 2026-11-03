<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Collections\Generic\EqualityComparer;
use Light\Linq\Enumerable;
use PHPUnit\Framework\TestCase;

final class EnumerableOuterJoinTest extends TestCase
{
    public function testLeftJoinPreservesLeftOrderDuplicatesAndInnerMatchOrder(): void
    {
        $left = Enumerable::from([['id' => 2], ['id' => 1], ['id' => 2], ['id' => 3]]);
        $right = [['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => 'Bob'], ['id' => 2, 'name' => 'Ben']];
        $query = $left->leftJoin($right, fn ($row) => $row['id'], fn ($row) => $row['id'],
            fn ($left, $right) => [$left['id'], $right['name'] ?? null]);
        self::assertSame([[2, 'Bob'], [2, 'Ben'], [1, 'Ada'], [2, 'Bob'], [2, 'Ben'], [3, null]], $query->toArray());
        self::assertSame([3], $query->where(fn ($pair) => $pair[1] === null)->select(fn ($pair) => $pair[0])->toArray());
    }

    public function testRightJoinPreservesRightOrderAndKeepsSelectorArgumentsLeftThenRight(): void
    {
        $left = Enumerable::from([['id' => 1, 'name' => 'Ada'], ['id' => 1, 'name' => 'Alice'], ['id' => 2, 'name' => 'Bob']]);
        $right = [['id' => 2], ['id' => 1], ['id' => 3], ['id' => 1]];
        self::assertSame([
            ['Bob', 2], ['Ada', 1], ['Alice', 1], [null, 3], ['Ada', 1], ['Alice', 1],
        ], $left->rightJoin($right, fn ($row) => $row['id'], fn ($row) => $row['id'],
            fn ($left, $right) => [$left['name'] ?? null, $right['id']])->toArray());
    }

    public function testEmptyMatchingSideProducesOneResultPerPreservedElement(): void
    {
        $identity = fn ($item) => $item;
        $pair = fn ($left, $right) => [$left, $right];
        self::assertSame([[1, null], [null, null], [1, null]],
            Enumerable::from([1, null, 1])->leftJoin([], $identity, $identity, $pair)->toArray());
        self::assertSame([[null, 1], [null, null], [null, 1]],
            Enumerable::empty()->rightJoin([1, null, 1], $identity, $identity, $pair)->toArray());
        self::assertSame([], Enumerable::empty()->leftJoin([], $identity, $identity, $pair)->toArray());
        self::assertSame([], Enumerable::empty()->rightJoin([], $identity, $identity, $pair)->toArray());
    }

    public function testDefaultKeyComparisonPreservesStrictTypesCompositeKeysAndObjectIdentity(): void
    {
        $object = (object) ['id' => 1];
        $other = (object) ['id' => 1];
        $keys = [1, '1', null, false, ['id' => 1], $object, $other];
        $left = array_map(fn ($key, $index) => ['key' => $key, 'label' => 'L'.$index], $keys, array_keys($keys));
        $right = array_map(fn ($row) => ['key' => $row['key'], 'label' => 'R'.substr($row['label'], 1)], array_slice($left, 0, -1));
        foreach (['leftJoin', 'rightJoin'] as $method) {
            $expected = array_map(fn ($index) => ['L'.$index, 'R'.$index], range(0, 5));
            if ($method === 'leftJoin') $expected[] = ['L6', null];
            self::assertSame($expected, Enumerable::from($left)->$method(
                $right, fn ($row) => $row['key'], fn ($row) => $row['key'],
                fn ($left, $right) => [$left['label'] ?? null, $right['label'] ?? null],
            )->toArray());
        }
    }

    public function testNullElementsCanMatchAndStillProduceEveryPair(): void
    {
        foreach (['leftJoin', 'rightJoin'] as $method) {
            self::assertSame([[null, null], [null, null]], Enumerable::from([null])->$method(
                [null, null], fn ($value) => $value, fn ($value) => $value,
                fn ($left, $right) => [$left, $right],
            )->toArray());
        }
    }

    public function testCustomComparerResolvesCollisionsAndPreservesMatchOrdering(): void
    {
        $comparer = new class implements EqualityComparer {
            public function hash(mixed $value): string { return 'collision'; }
            public function equals(mixed $left, mixed $right): bool { return strtolower($left) === strtolower($right); }
        };
        $left = Enumerable::from(['B', 'A', 'C']);
        $right = ['a', 'b', 'B', 'D'];
        $identity = fn ($value) => $value;
        $pair = fn ($left, $right) => [$left, $right];
        self::assertSame([['B', 'b'], ['B', 'B'], ['A', 'a'], ['C', null]],
            $left->leftJoin($right, $identity, $identity, $pair, $comparer)->toArray());
        self::assertSame([['A', 'a'], ['B', 'b'], ['B', 'B'], [null, 'D']],
            $left->rightJoin($right, $identity, $identity, $pair, $comparer)->toArray());
    }

    public function testBothOperatorsDeferWorkBufferOnlyMatchingSideAndRebuildOnEnumeration(): void
    {
        foreach (['leftJoin', 'rightJoin'] as $method) {
            $leftReads = $rightReads = $leftKeys = $rightKeys = $results = 0;
            $left = Enumerable::defer(static function () use (&$leftReads): iterable {
                foreach ([1, 2, 3] as $value) { ++$leftReads; yield $value; }
            });
            $right = Enumerable::defer(static function () use (&$rightReads): iterable {
                foreach ([2, 2, 4] as $value) { ++$rightReads; yield $value; }
            });
            $query = $left->$method($right,
                static function ($value) use (&$leftKeys) { ++$leftKeys; return $value; },
                static function ($value) use (&$rightKeys) { ++$rightKeys; return $value; },
                static function ($left, $right) use (&$results) { ++$results; return [$left, $right]; },
            );
            self::assertSame([0, 0, 0, 0, 0], [$leftReads, $rightReads, $leftKeys, $rightKeys, $results]);
            self::assertSame($method === 'leftJoin' ? [1, null] : [2, 2], $query->first());
            self::assertSame($method === 'leftJoin' ? [1, 3, 1, 3, 1] : [3, 1, 3, 1, 1],
                [$leftReads, $rightReads, $leftKeys, $rightKeys, $results]);
            self::assertSame($method === 'leftJoin' ? [[1, null], [2, 2], [2, 2], [3, null]] : [[2, 2], [2, 2], [null, 4]], $query->toArray());
            self::assertSame($method === 'leftJoin' ? [4, 6, 4, 6, 5] : [6, 4, 6, 4, 4],
                [$leftReads, $rightReads, $leftKeys, $rightKeys, $results]);
        }
    }

    public function testEmptyPreservedSideDoesNotTouchOtherSourceOrCallbacks(): void
    {
        $bad = fn () => throw new \RuntimeException('Unexpected callback');
        $unread = Enumerable::defer(static function (): iterable {
            throw new \RuntimeException('Unexpected source read'); yield;
        });
        self::assertSame([], Enumerable::empty()->leftJoin($unread, $bad, $bad, $bad)->toArray());
        self::assertSame([], $unread->rightJoin([], $bad, $bad, $bad)->toArray());
    }

    public function testTakeDoesNotReadAheadOnPreservedSideOrInvokeExtraResultSelectors(): void
    {
        foreach (['leftJoin', 'rightJoin'] as $method) {
            $preserved = Enumerable::defer(static function (): iterable {
                yield 1;
                throw new \RuntimeException('Unexpected read ahead');
            });
            $calls = 0;
            $selector = static function ($left, $right) use (&$calls) { ++$calls; return [$left, $right]; };
            $left = $method === 'leftJoin' ? $preserved : Enumerable::from([1, 1]);
            $right = $method === 'leftJoin' ? [1, 1] : $preserved;
            self::assertSame([[1, 1]], $left->$method($right, fn ($x) => $x, fn ($x) => $x, $selector)->take(1)->toArray());
            self::assertSame(1, $calls);
        }
    }

    public function testSingleUseGeneratorsAreSupportedWithoutRewindingMatchingSide(): void
    {
        foreach (['leftJoin', 'rightJoin'] as $method) {
            $left = (static function (): iterable { yield 'a' => 2; yield 'a' => 2; })();
            $right = (static function (): iterable { yield 'a' => 2; yield 'a' => 2; })();
            self::assertSame([[2, 2], [2, 2], [2, 2], [2, 2]], Enumerable::from($left)->$method(
                $right, fn ($x) => $x, fn ($x) => $x, fn ($left, $right) => [$left, $right],
            )->toArray());
        }
    }

    public function testSourceKeyAndResultSelectorExceptionsArePropagated(): void
    {
        foreach (['leftJoin', 'rightJoin'] as $method) {
            foreach (['leftSource', 'rightSource', 'leftKey', 'rightKey', 'result'] as $step) {
                $failure = new \RuntimeException($step);
                $bad = fn () => throw $failure;
                $badSource = Enumerable::defer(static function () use ($failure): iterable { throw $failure; yield; });
                $left = $step === 'leftSource' ? $badSource : Enumerable::from([1]);
                $right = $step === 'rightSource' ? $badSource : [1];
                try {
                    $left->$method($right, $step === 'leftKey' ? $bad : fn ($x) => $x,
                        $step === 'rightKey' ? $bad : fn ($x) => $x,
                        $step === 'result' ? $bad : fn ($left, $right) => [$left, $right])->toArray();
                    self::fail('Expected exception');
                } catch (\RuntimeException $error) {
                    self::assertSame($failure, $error);
                }
            }
        }
    }
}
