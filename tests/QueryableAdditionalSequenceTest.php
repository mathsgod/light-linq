<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\Expr;
use Light\Linq\Expressions\Expression;
use Light\Linq\Expressions\MethodCallExpression;
use Light\Linq\InMemoryQueryProvider;
use Light\Linq\IQueryProvider;
use Light\Linq\Queryable;
use PHPUnit\Framework\TestCase;

final class QueryableAdditionalSequenceTest extends TestCase
{
    private function query(iterable $rows): Queryable
    {
        return Queryable::from(new InMemoryQueryProvider(['rows' => $rows]), 'rows');
    }

    public function testAppendPrependReverseAndDefaultPreserveValuesAndSource(): void
    {
        $query = $this->query([1, 2, 2, null]);
        self::assertSame([0, 1, 2, 2, null, false], $query->prepend(0)->append(false)->toArray());
        self::assertSame([null, 2, 2, 1], $query->reverse()->toArray());
        self::assertSame([1, 2, 2, null], $query->toArray());
        self::assertSame([1, 2, 2, null], $query->defaultIfEmpty(99)->toArray());
        self::assertSame([null], $this->query([null])->defaultIfEmpty(99)->toArray());
        self::assertSame([99], $this->query([])->defaultIfEmpty(99)->toArray());
        self::assertSame([null], $this->query([])->defaultIfEmpty()->toArray());
        self::assertSame([false], $this->query([])->defaultIfEmpty(false)->toArray());
        self::assertSame([], $this->query([])->reverse()->toArray());
        self::assertSame([1], $this->query([])->append(1)->toArray());
        self::assertSame([1], $this->query([])->prepend(1)->toArray());
    }

    public function testTailOperationsPreserveOrderAndHandleCounts(): void
    {
        $query = $this->query([1, 2, 3, 4, 5]);
        self::assertSame([1, 2, 3], $query->skipLast(2)->toArray());
        self::assertSame([4, 5], $query->takeLast(2)->toArray());
        self::assertSame([1, 2, 3, 4, 5], $query->skipLast(0)->toArray());
        self::assertSame([], $query->takeLast(0)->toArray());
        self::assertSame([], $query->skipLast(10)->toArray());
        self::assertSame([1, 2, 3, 4, 5], $query->takeLast(10)->toArray());
        self::assertSame([], $this->query([])->takeLast(2)->toArray());
        self::assertSame([], $this->query([])->skipLast(2)->toArray());
        foreach (['skipLast', 'takeLast'] as $method) {
            try {
                $query->$method(-1);
                self::fail('Expected invalid count');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testWhilePredicatesStopAtFirstFalseAndSupportClosures(): void
    {
        $query = $this->query([['n' => 1], ['n' => 2], ['n' => 5], ['n' => 1]]);
        self::assertSame([5, 1], $query->skipWhile(Expr::lt(Expr::field('n'), 3))->select(Expr::field('n'))->toArray());
        self::assertSame([1, 2], $query->takeWhile(fn ($row) => $row->n < 3)->select(Expr::field('n'))->toArray());
        self::assertSame([1], $query->skipWhile(fn ($row) => $row->n < 3)->takeLast(1)->select(Expr::field('n'))->toArray());
        self::assertSame([], $query->skipWhile(Expr::value(true))->toArray());
        self::assertSame([], $query->takeWhile(Expr::value(false))->toArray());
        self::assertSame([null, true], $this->query([null, true])->skipWhile(Expr::value(null))->toArray());
        self::assertSame([], $this->query([1])->takeWhile(Expr::value(null))->toArray());
        self::assertSame([], $this->query([])->takeWhile(Expr::value(true))->toArray());
    }

    public function testOperationsBuildExpressionsWithoutExecutingProvider(): void
    {
        $provider = new class implements IQueryProvider {
            public int $calls = 0;
            public function execute(Expression $expression): mixed { ++$this->calls; return []; }
        };
        $query = Queryable::from($provider, 'rows');
        $operations = [
            'append' => [1], 'prepend' => [0], 'reverse' => [], 'defaultIfEmpty' => [99],
            'skipWhile' => [Expr::value(true)], 'takeWhile' => [Expr::value(true)],
            'skipLast' => [2], 'takeLast' => [2],
        ];
        foreach ($operations as $method => $args) {
            $next = $query->$method(...$args);
            self::assertNotSame($query, $next);
            self::assertSame($provider, $next->provider());
            self::assertInstanceOf(MethodCallExpression::class, $next->expression());
            self::assertSame($method, $next->expression()->method);
            self::assertSame($query->expression(), $next->expression()->source);
        }
        self::assertSame(0, $provider->calls);
    }

    public function testStreamingOperationsDoNotReadAheadAndReplay(): void
    {
        $reads = 0;
        $rows = \Light\Linq\Enumerable::defer(static function () use (&$reads): iterable {
            foreach ([1, 2, 3] as $n) { ++$reads; yield $n; }
        });
        $query = $this->query($rows);
        $prepended = $query->prepend(0);
        self::assertSame(0, $reads);
        self::assertSame([0], $prepended->take(1)->toArray());
        self::assertSame(0, $reads);
        self::assertSame([1], $query->append(99)->take(1)->toArray());
        self::assertSame(1, $reads);
        self::assertSame([1], $query->skipLast(1)->take(1)->toArray());
        self::assertSame(3, $reads);
        self::assertSame([2, 3], $query->takeLast(2)->toArray());
        self::assertSame([2, 3], $query->takeLast(2)->toArray());
        self::assertSame(9, $reads);
        self::assertSame([], $query->takeLast(0)->toArray());
        self::assertSame(9, $reads);
    }

    public function testSkipWhileDoesNotEvaluateLaterRowsAfterFirstFalse(): void
    {
        self::assertSame([['n' => 5], ['other' => 1]], $this->query([['n' => 1], ['n' => 5], ['other' => 1]])
            ->skipWhile(Expr::lt(Expr::field('n'), 3))->toArray());
        self::assertSame([['n' => 1]], $this->query([['n' => 1], ['n' => 5], ['other' => 1]])
            ->takeWhile(Expr::lt(Expr::field('n'), 3))->toArray());
    }
}
