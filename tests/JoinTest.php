<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\Expr;
use Light\Linq\InMemoryQueryProvider;
use Light\Linq\Queryable;
use PHPUnit\Framework\TestCase;

final class JoinTest extends TestCase
{
    private function sources(): array
    {
        $provider = new InMemoryQueryProvider([
            'orders' => [
                ['id' => 1, 'customerId' => 10],
                ['id' => 2, 'customerId' => 20],
                ['id' => 3, 'customerId' => null],
                ['id' => 4, 'customerId' => 99],
            ],
            'customers' => [
                ['id' => 10, 'name' => 'Ada'],
                ['id' => 10, 'name' => 'Alice'],
                ['id' => 20, 'name' => 'Bob'],
                ['id' => null, 'name' => 'Null key'],
            ],
        ]);
        return [Queryable::from($provider, 'orders'), Queryable::from($provider, 'customers')];
    }

    public function testInnerJoinPreservesDuplicateMatchesAndComposesOverProjection(): void
    {
        [$orders, $customers] = $this->sources();
        $query = $orders->join($customers, Expr::field('customerId'), Expr::field('id'),
            Expr::projection(['orderId' => 'outer.id', 'name' => 'inner.name']));
        self::assertSame([
            ['orderId' => 1, 'name' => 'Ada'],
            ['orderId' => 1, 'name' => 'Alice'],
            ['orderId' => 2, 'name' => 'Bob'],
        ], $query->toArray());
        self::assertSame(3, $query->count());
        self::assertSame(['Alice'], $query->where(Expr::eq(Expr::field('orderId'), 1))
            ->orderByDescending(Expr::field('name'))->take(1)->select(Expr::field('name'))->toArray());
    }

    public function testLeftJoinKeepsUnmatchedRowsAndDoesNotMatchNullKeys(): void
    {
        [$orders, $customers] = $this->sources();
        $query = $orders->leftJoin($customers, Expr::field('customerId'), Expr::field('id'),
            Expr::projection(['id' => 'outer.id', 'name' => 'inner.name']));
        self::assertSame([
            ['id' => 1, 'name' => 'Ada'], ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'], ['id' => 3, 'name' => null], ['id' => 4, 'name' => null],
        ], $query->toArray());
        self::assertSame(2, $query->where(Expr::eq(Expr::field('name'), null))->count());
    }

    public function testFilteredAndPagedSourcesAndEmptyInner(): void
    {
        [$orders, $customers] = $this->sources();
        $query = $orders->orderByDescending(Expr::field('id'))->take(2)->leftJoin(
            $customers->where(Expr::eq(Expr::field('name'), 'Ada')),
            Expr::field('customerId'), Expr::field('id'), Expr::fields('outer.id', 'inner.name'));
        self::assertSame([['id' => 4, 'name' => null], ['id' => 3, 'name' => null]], $query->toArray());
        self::assertSame(0, $orders->join($customers->take(0), Expr::field('customerId'),
            Expr::field('id'), Expr::field('outer.id'))->count());
    }

    public function testSelfJoinAndChainedJoin(): void
    {
        [$orders, $customers] = $this->sources();
        $query = $orders->join($orders, Expr::field('id'), Expr::field('id'),
            Expr::projection(['id' => 'outer.id', 'customerId' => 'inner.customerId']))
            ->join($customers, Expr::field('customerId'), Expr::field('id'),
                Expr::projection(['id' => 'outer.id', 'name' => 'inner.name']));
        self::assertSame(3, $query->count());
        self::assertSame(['id' => 1, 'name' => 'Ada'], $query->first());
    }

    public function testCompositeKeys(): void
    {
        [$orders] = $this->sources();
        $key = Expr::projection(['id' => 'id', 'customer' => 'customerId']);
        self::assertSame([1, 2, 4], $orders->join($orders, $key, $key, Expr::field('outer.id'))->toArray());
    }

    public function testDifferentProvidersAreRejected(): void
    {
        [$orders] = $this->sources();
        [$other] = $this->sources();
        $this->expectException(\InvalidArgumentException::class);
        $orders->join($other, Expr::field('id'), Expr::field('id'), Expr::field('outer.id'));
    }
}
