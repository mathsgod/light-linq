<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\{Expr, InMemoryQueryProvider, Queryable};
use PHPUnit\Framework\TestCase;

final class SelectManyTest extends TestCase
{
    private function queries(): array
    {
        $p = new InMemoryQueryProvider([
            'customers' => [['id' => 1], ['id' => 2], ['id' => null]],
            'orders' => [['id' => 10, 'customerId' => 1], ['id' => 11, 'customerId' => 1], ['id' => 12, 'customerId' => null]],
            'empty' => [],
        ]);
        return [Queryable::from($p, 'customers'), Queryable::from($p, 'orders'), Queryable::from($p, 'empty')];
    }

    public function testCrossProductPreservesDuplicatesAndScalarProjection(): void
    {
        [$customers, $orders] = $this->queries();
        self::assertSame([10, 11, 12, 10, 11, 12, 10, 11, 12],
            $customers->selectMany($orders, Expr::field('inner.id'))->toArray());
        self::assertSame(9, $customers->selectMany($orders, Expr::field('inner.id'))->count());
    }

    public function testCorrelatedPredicateAndSubsequentComposition(): void
    {
        [$customers, $orders] = $this->queries();
        $query = $customers->selectMany($orders,
            Expr::projection(['customer' => 'outer.id', 'order' => 'inner.id']),
            Expr::eq(Expr::field('outer.id'), Expr::field('inner.customerId')));
        self::assertSame([['customer' => 1, 'order' => 10], ['customer' => 1, 'order' => 11]], $query->toArray());
        self::assertSame([11], $query->where(Expr::gt(Expr::field('order'), 10))
            ->orderByDescending(Expr::field('order'))->select(Expr::field('order'))->take(1)->toArray());
        self::assertSame(21, $query->sum(Expr::field('order')));
    }

    public function testNonEqualityAndNullPredicates(): void
    {
        [$customers, $orders] = $this->queries();
        self::assertSame([10, 11], $customers->where(Expr::eq(Expr::field('id'), 2))
            ->selectMany($orders, Expr::field('inner.id'),
                Expr::gt(Expr::field('outer.id'), Expr::field('inner.customerId')))->toArray());
        $match = Expr::either(Expr::eq(Expr::field('outer.id'), Expr::field('inner.customerId')),
            Expr::eq(Expr::field('inner.customerId'), null));
        self::assertSame([10, 11, 12, 12, 12], $customers->selectMany($orders, Expr::field('inner.id'), $match)->toArray());
    }

    public function testPagingIsAppliedToSourcesBeforeFlattening(): void
    {
        [$customers, $orders] = $this->queries();
        self::assertSame([11], $customers->take(1)->selectMany($orders->skip(1)->take(1), Expr::field('inner.id'))->toArray());
    }

    public function testEmptySourcesProduceNoRows(): void
    {
        [$customers, $orders, $empty] = $this->queries();
        self::assertSame([], $customers->selectMany($empty, Expr::field('outer.id'))->toArray());
        self::assertSame([], $empty->selectMany($orders, Expr::field('inner.id'))->toArray());
    }

    public function testEmptyOuterDoesNotConsumeInner(): void
    {
        $inner = (static function () { throw new \RuntimeException('Must not read inner'); yield 1; })();
        $p = new InMemoryQueryProvider(['empty' => [], 'inner' => $inner]);
        self::assertSame([], Queryable::from($p, 'empty')->selectMany(Queryable::from($p, 'inner'), Expr::field('inner.id'))->toArray());
    }

    public function testInnerGeneratorIsReadOnceForAllOuterRows(): void
    {
        $inner = (static function () { yield ['id' => 10]; yield ['id' => 11]; })();
        $p = new InMemoryQueryProvider(['outer' => [['id' => 1], ['id' => 2]], 'inner' => $inner]);
        self::assertSame([10, 11, 10, 11], Queryable::from($p, 'outer')
            ->selectMany(Queryable::from($p, 'inner'), Expr::field('inner.id'))->toArray());
    }

    public function testDifferentProvidersAreRejected(): void
    {
        [$customers] = $this->queries();
        $this->expectException(\InvalidArgumentException::class);
        $customers->selectMany(Queryable::from(new InMemoryQueryProvider([]), 'orders'), Expr::field('inner.id'));
    }
}
