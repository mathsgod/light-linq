<?php
declare(strict_types=1);
namespace PhpLinq\Tests;
use PhpLinq\{Expr, Grouping, InMemoryQueryProvider, Queryable};
use PHPUnit\Framework\TestCase;
final class GroupingTest extends TestCase
{
    private function query(): Queryable
    {
        return Queryable::from(new InMemoryQueryProvider(['orders' => [
            ['customer' => 1, 'amount' => 10],
            ['customer' => 1, 'amount' => 20],
            ['customer' => 2, 'amount' => null],
            ['customer' => null, 'amount' => 5],
        ]]), 'orders');
    }
    public function testGroupedAggregatesAndHaving(): void
    {
        $query = $this->query()->groupBy(Expr::field('customer'));
        self::assertSame([
            ['customer' => 1, 'count' => 2, 'sum' => 30, 'avg' => 15, 'min' => 10, 'max' => 20],
        ], $query->where(Expr::gte(Expr::count(), 2))->select(Expr::projection([
            'customer' => Expr::key(), 'count' => Expr::count(),
            'sum' => Expr::sum(Expr::field('amount')), 'avg' => Expr::avg(Expr::field('amount')),
            'min' => Expr::min(Expr::field('amount')), 'max' => Expr::max(Expr::field('amount')),
        ]))->toArray());
        self::assertSame(3, $query->count());
        self::assertInstanceOf(Grouping::class, $query->first());
        self::assertSame([30, null, 5], $query->select(Expr::sum(Expr::field('amount')))->toArray());
        self::assertSame([2, 0, 1], $query->select(Expr::count(Expr::field('amount')))->toArray());
    }
    public function testCompositeKeysAndEmptyInput(): void
    {
        self::assertSame([1, 2, null], $this->query()->groupBy(Expr::fields('customer'))
            ->select(Expr::key('customer'))->toArray());
        self::assertSame([], $this->query()->take(0)->groupBy(Expr::field('customer'))->toArray());
    }
    public function testDistinctAndPaginationOrder(): void
    {
        $query = $this->query()->select(Expr::field('customer'));
        self::assertSame([1, 2, null], $query->distinct()->toArray());
        self::assertSame([1], $query->take(2)->distinct()->toArray());
        self::assertSame([1, 2], $query->distinct()->take(2)->toArray());
        self::assertSame(3, $query->distinct()->count());
        self::assertSame([['customer' => 1], ['customer' => 2], ['customer' => null]],
            $this->query()->select(Expr::fields('customer'))->distinct()->toArray());
    }
}
