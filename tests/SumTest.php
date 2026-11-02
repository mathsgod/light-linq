<?php
declare(strict_types=1);
namespace Light\Linq\Tests;
use Light\Linq\{Expr, InMemoryQueryProvider, Queryable};
use PHPUnit\Framework\TestCase;
final class SumTest extends TestCase
{
    public function testAverageMinAndMaxIgnoreNullsAndRespectPaging(): void
    {
        $q = Queryable::from(new InMemoryQueryProvider(['orders' => [
            ['id' => 1, 'amount' => 10], ['id' => 2, 'amount' => 21], ['id' => 3, 'amount' => null],
        ]]), 'orders');
        self::assertSame(15.5, $q->average(Expr::field('amount')));
        self::assertSame(10, $q->min(Expr::field('amount')));
        self::assertSame(21, $q->max(Expr::field('amount')));
        foreach (['average', 'min', 'max'] as $method) {
            self::assertNull($q->take(0)->$method(Expr::field('amount')));
            self::assertNull($q->where(Expr::eq(Expr::field('id'), 3))->$method(Expr::field('amount')));
            self::assertEquals(21, $q->orderByDescending(Expr::field('id'))->take(2)->$method(Expr::field('amount')));
        }
    }

    public function testSumAfterFilteringPagingProjectionAndGrouping(): void
    {
        $q = Queryable::from(new InMemoryQueryProvider(['orders' => [
            ['id' => 1, 'amount' => 10], ['id' => 2, 'amount' => 20], ['id' => 3, 'amount' => null],
        ]]), 'orders');
        self::assertSame(30, $q->sum(Expr::field('amount')));
        self::assertSame(20, $q->where(Expr::gt(Expr::field('id'), 1))->sum(Expr::field('amount')));
        self::assertSame(0, $q->take(0)->sum(Expr::field('amount')));
        self::assertSame(0, $q->where(Expr::eq(Expr::field('id'), 3))->sum(Expr::field('amount')));
        self::assertSame(20, $q->orderByDescending(Expr::field('id'))->take(2)->sum(Expr::field('amount')));
        self::assertSame(30, $q->select(Expr::projection(['value' => Expr::field('amount')]))->sum(Expr::field('value')));
        self::assertSame(30, $q->groupBy(Expr::field('id'))->select(Expr::projection([
            'total' => Expr::sum(Expr::field('amount')),
        ]))->sum(Expr::field('total')));
    }
}
