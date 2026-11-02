<?php

declare(strict_types=1);
namespace Light\Linq\Tests;

use Light\Linq\{Expr, InMemoryQueryProvider, Queryable};
use PHPUnit\Framework\TestCase;

final class LastOperatorsTest extends TestCase
{
    private function query(): Queryable
    {
        return Queryable::from(new InMemoryQueryProvider(['rows' => [
            ['id' => 1, 'value' => false], ['id' => 2, 'value' => 5], ['id' => 3, 'value' => null],
        ]]), 'rows');
    }

    public function testSingleOrDefaultHandlesEmptyOneAndNull(): void
    {
        $q = $this->query();
        $default = new \stdClass();
        self::assertSame($default, $q->take(0)->singleOrDefault(default: $default));
        self::assertSame(2, $q->singleOrDefault(Expr::eq(Expr::field('id'), 2))['id']);
        self::assertFalse($q->take(1)->select(Expr::field('value'))->singleOrDefault(default: true));
        self::assertNull($q->skip(2)->select(Expr::field('value'))->singleOrDefault(default: true));
    }

    public function testSingleOrDefaultStillRejectsMultipleResults(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->query()->singleOrDefault();
    }

    public function testLastUsesSequenceOrderAndPredicate(): void
    {
        $q = $this->query();
        self::assertSame(3, $q->last()['id']);
        self::assertSame(2, $q->last(Expr::lt(Expr::field('id'), 3))['id']);
        self::assertSame(1, $q->orderByDescending(Expr::field('id'))->last()['id']);
        self::assertSame(2, $q->orderBy(Expr::field('id'))->take(2)->last()['id']);
        self::assertSame(2, $q->orderBy(Expr::field('id'))->skip(1)->take(1)->lastOrDefault()['id']);
    }

    public function testLastDefaultPreservesNullAndFalse(): void
    {
        $q = $this->query();
        self::assertNull($q->take(0)->lastOrDefault());
        self::assertSame('empty', $q->lastOrDefault(Expr::gt(Expr::field('id'), 9), 'empty'));
        self::assertNull($q->select(Expr::field('value'))->lastOrDefault(default: true));
        self::assertFalse($q->take(1)->select(Expr::field('value'))->lastOrDefault(default: true));
    }

    public function testLastRejectsEmpty(): void
    {
        $this->expectException(\UnderflowException::class);
        $this->query()->take(0)->last();
    }
}
