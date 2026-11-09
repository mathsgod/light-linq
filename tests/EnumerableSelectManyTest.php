<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\Enumerable;
use PHPUnit\Framework\TestCase;

final class EnumerableSelectManyTest extends TestCase
{
    public function testCombinesOuterAndInnerWithIndexedCollectionSelector(): void
    {
        $people = Enumerable::from([
            ['name' => 'Ada', 'hobbies' => ['music', 'swimming']],
            ['name' => 'Bob', 'hobbies' => []],
            ['name' => 'Cara', 'hobbies' => ['reading']],
        ]);
        $indexes = [];
        $query = $people->selectMany(function ($person, $index) use (&$indexes) {
            $indexes[] = $index;
            return $person['hobbies'];
        }, resultSelector: fn ($person, $hobby) => $person['name'].': '.$hobby);
        self::assertSame([], $indexes);
        self::assertSame(['Ada: music', 'Ada: swimming', 'Cara: reading'], $query->toArray());
        self::assertSame([0, 1, 2], $indexes);
        self::assertSame(['Ada: music', 'Ada: swimming', 'Cara: reading'], $query->toArray());
        self::assertSame([0, 1, 2, 0, 1, 2], $indexes);
    }

    public function testStreamsInnerGeneratorsAndTakeDoesNotReadAhead(): void
    {
        $reads = 0;
        $query = Enumerable::from([10, 20])->selectMany(
            static function ($outer) use (&$reads): iterable {
                ++$reads;
                yield 1;
                throw new \RuntimeException('Must not read ahead');
            },
            fn ($outer, $inner) => $outer + $inner,
        );
        self::assertSame(0, $reads);
        self::assertSame([11], $query->take(1)->toArray());
        self::assertSame(1, $reads);
    }

    public function testEmptySourcesAndNullInnerElements(): void
    {
        $fail = fn () => throw new \RuntimeException('Must not execute');
        self::assertSame([], Enumerable::empty()->selectMany($fail, $fail)->toArray());
        self::assertSame([], Enumerable::from([1])->selectMany(fn ($x) => [], $fail)->toArray());
        self::assertSame([[1, null], [1, false], [1, false]], Enumerable::from([1])
            ->selectMany(fn ($x) => [null, false, false], fn ($outer, $inner) => [$outer, $inner])->toArray());
    }

    public function testResultSelectorFailureOccursDuringEnumeration(): void
    {
        $query = Enumerable::from([1])->selectMany(fn ($x) => [2], fn ($outer, $inner) => throw new \RuntimeException('projection'));
        $this->expectExceptionMessage('projection');
        $query->toArray();
    }
}
