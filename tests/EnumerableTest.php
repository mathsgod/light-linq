<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\Enumerable;
use Light\Linq\Expr;
use Light\Linq\InMemoryQueryProvider;
use Light\Linq\Queryable;
use Light\Linq\Collections\Generic\EqualityComparer;
use PHPUnit\Framework\TestCase;
use UnderflowException;
use UnexpectedValueException;

final class EnumerableTest extends TestCase
{
    public function testPipelineIsLazyAndTakeStopsEnumeration(): void
    {
        $started = 0;
        $visited = 0;
        $source = Enumerable::defer(static function () use (&$started): iterable {
            ++$started;
            yield from [1, 2, 3, 4, 5, 6];
        });

        $query = $source
            ->where(static function (int $number) use (&$visited): bool {
                ++$visited;
                return $number % 2 === 0;
            })
            ->select(static fn (int $number): int => $number * 10)
            ->take(2);

        self::assertSame(0, $started);
        self::assertSame(0, $visited);
        self::assertSame([20, 40], $query->toArray());
        self::assertSame(1, $started);
        self::assertSame(4, $visited);
    }

    public function testProjectionFilteringAndFlattening(): void
    {
        $result = Enumerable::from([[1, 2], [3], [4, 5]])
            ->selectMany(static fn (array $numbers): array => $numbers)
            ->where(static fn (int $number): bool => $number > 2)
            ->select(static fn (int $number): int => $number * 2)
            ->toArray();

        self::assertSame([6, 8, 10], $result);
    }

    public function testPagingAndSequenceComposition(): void
    {
        $result = Enumerable::from([1, 2, 3, 4, 5])
            ->skipWhile(static fn (int $number): bool => $number < 3)
            ->takeWhile(static fn (int $number): bool => $number < 5)
            ->prepend(0)
            ->append(5)
            ->concat([6])
            ->toArray();

        self::assertSame([0, 3, 4, 5, 6], $result);
        self::assertSame([4, 5], Enumerable::from([1, 2, 3, 4, 5])->takeLast(2)->toArray());
    }

    public function testDistinctReverseAndChunk(): void
    {
        $result = Enumerable::from([1, 1, 2, 3, 3])
            ->distinct()
            ->reverse()
            ->chunk(2)
            ->toArray();

        self::assertSame([[3, 2], [1]], $result);
    }

    public function testStableMultiColumnOrdering(): void
    {
        $people = [
            ['department' => 'B', 'age' => 30, 'name' => 'first'],
            ['department' => 'A', 'age' => 40, 'name' => 'second'],
            ['department' => 'A', 'age' => 40, 'name' => 'third'],
            ['department' => 'A', 'age' => 20, 'name' => 'fourth'],
        ];

        $names = Enumerable::from($people)
            ->orderBy(static fn (array $person): string => $person['department'])
            ->thenByDescending(static fn (array $person): int => $person['age'])
            ->select(static fn (array $person): string => $person['name'])
            ->toArray();

        self::assertSame(['second', 'third', 'fourth', 'first'], $names);
    }

    public function testStableOrderingUsesIterationOrderRatherThanAssociativeKeys(): void
    {
        $items = ['z' => ['group' => 1, 'name' => 'first'], 'a' => ['group' => 1, 'name' => 'second']];

        $names = Enumerable::from($items)
            ->orderBy(static fn (array $item): int => $item['group'])
            ->select(static fn (array $item): string => $item['name'])
            ->toArray();

        self::assertSame(['first', 'second'], $names);
    }

    public function testQuantifiersAndElementOperators(): void
    {
        $numbers = Enumerable::from([1, 2, 3]);

        self::assertSame(2, $numbers->count(static fn (int $n): bool => $n > 1));
        self::assertTrue($numbers->any(static fn (int $n): bool => $n === 2));
        self::assertTrue($numbers->all(static fn (int $n): bool => $n > 0));
        self::assertTrue($numbers->contains(3));
        self::assertSame(2, $numbers->first(static fn (int $n): bool => $n > 1));
        self::assertSame(3, $numbers->last());
        self::assertSame(2, $numbers->single(static fn (int $n): bool => $n === 2));
        self::assertSame('none', $numbers->firstOrDefault(
            static fn (int $n): bool => $n > 9,
            'none',
        ));
    }

    public function testSingleRejectsMultipleMatches(): void
    {
        $this->expectException(UnexpectedValueException::class);
        Enumerable::from([1, 2])->single();
    }

    public function testFirstRejectsEmptySequence(): void
    {
        $this->expectException(UnderflowException::class);
        Enumerable::from([])->first();
    }

    public function testNumericAggregates(): void
    {
        $orders = Enumerable::from([
            ['total' => 10],
            ['total' => 20],
            ['total' => 30],
        ]);

        self::assertSame(60, $orders->sum(static fn (array $order): int => $order['total']));
        self::assertSame(20, $orders->average(static fn (array $order): int => $order['total']));
        self::assertSame(6, Enumerable::from([1, 2, 3])->aggregate(
            static fn (int $sum, int $number): int => $sum + $number,
            0,
        ));
    }

    public function testMinMaxMinByAndMaxBy(): void
    {
        $people = Enumerable::from([
            ['name' => 'Ada', 'age' => 36],
            ['name' => 'Bob', 'age' => 22],
            ['name' => 'Cara', 'age' => 41],
        ]);

        self::assertSame(22, $people->min(static fn (array $person): int => $person['age']));
        self::assertSame(41, $people->max(static fn (array $person): int => $person['age']));
        self::assertSame('Bob', $people->minBy(
            static fn (array $person): int => $person['age'],
        )['name']);
        self::assertSame('Cara', $people->maxBy(
            static fn (array $person): int => $person['age'],
        )['name']);
    }

    public function testExtremesRejectEmptySequence(): void
    {
        $this->expectException(UnderflowException::class);
        Enumerable::from([])->min();
    }

    public function testSetOperatorsPreserveOrderAndReturnDistinctValues(): void
    {
        $numbers = Enumerable::from([3, 1, 2, 2, 4]);

        self::assertSame([3, 1], $numbers->except([2, 4])->toArray());
        self::assertSame([3, 2], $numbers->intersect([2, 3, 9])->toArray());
        self::assertSame([3, 1, 2, 4, 5], $numbers->union([2, 5, 3])->toArray());
    }

    public function testSetOperatorsAcceptCustomEqualityComparer(): void
    {
        $caseInsensitive = new class implements EqualityComparer {
            public function equals(mixed $left, mixed $right): bool
            {
                return strtolower((string) $left) === strtolower((string) $right);
            }

            public function hash(mixed $value): string
            {
                return strtolower((string) $value);
            }
        };

        self::assertSame(
            ['Ada', 'Bob'],
            Enumerable::from(['Ada', 'Bob'])->union(['ADA'], $caseInsensitive)->toArray(),
        );
    }

    public function testShuffleReturnsACompletePermutation(): void
    {
        $source = range(1, 100);
        $shuffled = Enumerable::from($source)->shuffle()->toArray();

        self::assertCount(100, $shuffled);
        sort($shuffled);
        self::assertSame($source, $shuffled);
    }

    public function testRelationalJoin(): void
    {
        $users = Enumerable::from([
            ['id' => 1, 'name' => 'Ada'],
            ['id' => 2, 'name' => 'Bob'],
            ['id' => 3, 'name' => 'Cara'],
        ]);
        $orders = [
            ['userId' => 1, 'total' => 10],
            ['userId' => 1, 'total' => 20],
            ['userId' => 3, 'total' => 30],
        ];

        $result = $users->join(
            $orders,
            static fn (array $user): int => $user['id'],
            static fn (array $order): int => $order['userId'],
            static fn (array $user, array $order): array => [
                'name' => $user['name'],
                'total' => $order['total'],
            ],
        )->toArray();

        self::assertSame([
            ['name' => 'Ada', 'total' => 10],
            ['name' => 'Ada', 'total' => 20],
            ['name' => 'Cara', 'total' => 30],
        ], $result);
    }

    public function testQueryableCanContinueAsEnumerableWithCallbacks(): void
    {
        $provider = new InMemoryQueryProvider([
            'users' => [
                ['name' => 'Ada', 'active' => true],
                ['name' => 'Bob', 'active' => false],
                ['name' => 'Cara', 'active' => true],
            ],
        ]);

        $names = Queryable::from($provider, 'users')
            ->where(Expr::eq(Expr::field('active'), true))
            ->asEnumerable()
            ->where(static fn (array $user): bool => strlen($user['name']) > 3)
            ->select(static fn (array $user): string => strtoupper($user['name']))
            ->toArray();

        self::assertSame(['CARA'], $names);
    }
}
