<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\Enumerable;
use Light\Linq\Expr;
use Light\Linq\InMemoryQueryProvider;
use Light\Linq\Queryable;
use PHPUnit\Framework\TestCase;

final class LongCountTest extends TestCase
{
    public function testEnumerableCountsWithAndWithoutPredicate(): void
    {
        $source = Enumerable::from([1, 2, 3, 4]);
        self::assertSame(4, $source->longCount());
        self::assertSame(2, $source->longCount(fn ($x) => $x % 2 === 0));
        self::assertSame(0, $source->longCount(fn ($x) => false));
        self::assertSame(0, Enumerable::empty()->longCount());
    }

    public function testAsEnumerablePreservesIdentityWithoutReadingSource(): void
    {
        $reads = 0;
        $source = Enumerable::defer(static function () use (&$reads): iterable {
            ++$reads;
            yield 1;
        });
        self::assertSame($source, $source->asEnumerable());
        self::assertSame(0, $reads);
        self::assertSame(1, $source->asEnumerable()->longCount());
        self::assertSame(1, $reads);
        self::assertSame(1, $source->asEnumerable()->longCount());
        self::assertSame(2, $reads);
    }

    public function testQueryableCountsPredicatesAndSupportsEnumerableBoundary(): void
    {
        $provider = new InMemoryQueryProvider(['rows' => [['n' => 1], ['n' => 2], ['n' => 3]], 'empty' => []]);
        $query = Queryable::from($provider, 'rows');
        self::assertSame(3, $query->longCount());
        self::assertSame(2, $query->longCount(Expr::gt(Expr::field('n'), 1)));
        self::assertSame(1, $query->longCount(fn ($row) => $row->n == 2));
        self::assertSame(3, $query->longCount());
        self::assertSame(2, $query->asEnumerable()->longCount(fn ($row) => $row['n'] > 1));
        self::assertSame(0, Queryable::from($provider, 'empty')->longCount());
    }
}
