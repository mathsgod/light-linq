<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\Enumerable;
use PHPUnit\Framework\TestCase;

final class IndexedCallbackTest extends TestCase
{
    public function testSelectAndWhereUseSourcePositionsRatherThanArrayKeys(): void
    {
        $source = Enumerable::from(['a' => 'Ada', 99 => 'Bob', 'c' => 'Cara']);
        self::assertSame(['0:Ada', '1:Bob', '2:Cara'], $source->select(fn ($v, $i) => "$i:$v")->toArray());
        self::assertSame(['Ada', 'Cara'], $source->where(fn ($v, $i) => $i % 2 === 0)->toArray());
        self::assertSame(['0:Ada', '1:Cara'], $source->where(fn ($v, $i) => $i % 2 === 0)->select(fn ($v, $i) => "$i:$v")->toArray());
    }

    public function testSelectManyIndexesOuterElementsIncludingEmptyGroups(): void
    {
        self::assertSame(['0:a', '0:b', '2:c'], Enumerable::from([['a', 'b'], [], ['c']])
            ->selectMany(fn ($values, $i) => array_map(fn ($v) => "$i:$v", $values))->toArray());
    }

    public function testWhileOperatorsShortCircuitAndDoNotUseFilteredPositions(): void
    {
        $seen = [];
        $query = Enumerable::from([10, 20, 30, 40])->skipWhile(function ($v, $i) use (&$seen) {
            $seen[] = [$v, $i];
            return $i < 2;
        });
        self::assertSame([30, 40], $query->toArray());
        self::assertSame([[10, 0], [20, 1], [30, 2]], $seen);
        $seen = [];
        self::assertSame([10, 20], Enumerable::from([10, 20, 30, 40])->takeWhile(function ($v, $i) use (&$seen) {
            $seen[] = $i;
            return $i < 2;
        })->toArray());
        self::assertSame([0, 1, 2], $seen);
    }

    public function testCallbacksRemainLazyAndIndexesResetOnEnumeration(): void
    {
        foreach (['select', 'where', 'selectMany', 'skipWhile', 'takeWhile'] as $method) {
            $seen = [];
            $callback = function ($v, $i) use (&$seen, $method) {
                $seen[] = $i;
                return match ($method) {
                    'selectMany' => [$v],
                    'skipWhile' => true,
                    default => true,
                };
            };
            $query = Enumerable::from([1, 2])->$method($callback);
            self::assertSame([], $seen);
            $query->toArray();
            $query->toArray();
            self::assertSame([0, 1, 0, 1], $seen);
        }
    }

    public function testBuiltinFunctionsKeepTheirOriginalSingleArgumentBehavior(): void
    {
        self::assertSame([1, 2], Enumerable::from(['a', 'bb'])->select('strlen')->toArray());
        self::assertSame(['a', 'b'], Enumerable::from([' a ', ' b '])->select('trim')->toArray());
        self::assertSame([1], Enumerable::from([1, '2'])->where('is_int')->toArray());
        self::assertSame([1, 2], Enumerable::from([[1], [2]])->selectMany('array_values')->toArray());
        self::assertSame(['x'], Enumerable::from([1, 2, 'x'])->skipWhile('is_int')->toArray());
        self::assertSame([1, 2], Enumerable::from([1, 2, 'x'])->takeWhile('is_int')->toArray());
    }

    public function testInvokableMethodsOptionalParametersAndVariadicsReceiveIndex(): void
    {
        $callback = new class {
            public function __invoke($v, $i): string { return "$i:$v"; }
            public function project($v, $i = -1): string { return "$i:$v"; }
        };
        $source = Enumerable::from(['a', 'b']);
        self::assertSame(['0:a', '1:b'], $source->select($callback)->toArray());
        self::assertSame(['0:a', '1:b'], $source->select([$callback, 'project'])->toArray());
        self::assertSame([['a', 0], ['b', 1]], $source->select(fn (...$args) => $args)->toArray());
        self::assertSame([], Enumerable::empty()->select(fn ($v, $i) => throw new \RuntimeException())->toArray());
    }
}
