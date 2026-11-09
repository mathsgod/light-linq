<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\Enumerable;
use Light\Linq\Expr;
use Light\Linq\Expressions\MethodCallExpression;
use Light\Linq\Expressions\SourceExpression;
use Light\Linq\InMemoryQueryProvider;
use Light\Linq\Queryable;
use PHPUnit\Framework\TestCase;

final class PagingAndNullableAverageTest extends TestCase
{
    public function testNonpositivePagingMatchesDotNetAndDoesNotConsumeTakeSource(): void
    {
        foreach ([-1, PHP_INT_MIN, 0] as $count) {
            $reads = 0;
            $source = Enumerable::defer(static function () use (&$reads): iterable {
                foreach ([1, null, 3] as $n) { ++$reads; yield $n; }
            });
            self::assertSame([], $source->take($count)->toArray());
            self::assertSame(0, $reads);
            self::assertSame([1, null, 3], $source->skip($count)->toArray());
            self::assertSame(3, $reads);
            self::assertSame([1, null, 3], $source->skip($count)->toArray());
            self::assertSame(6, $reads);
            $query = Queryable::from(new InMemoryQueryProvider(['rows' => $source]), 'rows');
            self::assertSame([], $query->take($count)->toArray());
            self::assertSame(6, $reads);
            self::assertSame([1, null, 3], $query->skip($count)->toArray());
            self::assertSame(9, $reads);
        }
    }

    public function testExternallyBuiltNegativePagingExpressionsUseSameRules(): void
    {
        $provider = new InMemoryQueryProvider(['rows' => [1, 2, 3]]);
        foreach (['skip' => [1, 2, 3], 'take' => []] as $method => $expected) {
            $result = $provider->execute(new MethodCallExpression(new SourceExpression('rows'), $method, ['count' => -1]));
            self::assertSame($expected, iterator_to_array($result, false));
        }
    }

    public function testAverageExcludesNullsFromSumAndDivisor(): void
    {
        self::assertSame(2, Enumerable::from([1, null, 3])->average());
        self::assertSame(0, Enumerable::from([null, 0, null])->average());
        self::assertSame(1.5, Enumerable::from([null, 1.0, 2.0, null])->average());
        self::assertNull(Enumerable::from([null, null])->average());
    }

    public function testSelectorIsCalledOncePerElementAndNullResultIsIgnored(): void
    {
        $calls = 0;
        $source = Enumerable::from([['n' => 1], ['n' => null], ['n' => 3]]);
        $selector = static function ($row) use (&$calls) { ++$calls; return $row['n']; };
        self::assertSame(2, $source->average($selector));
        self::assertSame(3, $calls);
        self::assertSame(2, $source->average($selector));
        self::assertSame(6, $calls);
        self::assertNull($source->average(fn ($row) => null));
        $query = Queryable::from(new InMemoryQueryProvider(['rows' => $source]), 'rows');
        self::assertSame(2, $query->average(Expr::field('n')));
    }
}
