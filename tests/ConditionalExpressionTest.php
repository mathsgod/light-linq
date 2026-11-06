<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\{ClosureTranslator, ClosureTranslationException, Expr, InMemoryQueryProvider, Queryable};
use Light\Linq\Expressions\{CoalesceExpression, ConditionalExpression};
use PHPUnit\Framework\TestCase;

final class ConditionalExpressionTest extends TestCase
{
    private function orders(): Queryable
    {
        return Queryable::from(new InMemoryQueryProvider(['orders' => [
            (object) ['id' => 1, 'name' => null, 'backup' => 'Guest', 'total' => 0],
            (object) ['id' => 2, 'name' => '', 'backup' => 'Other', 'total' => 20],
            (object) ['id' => 3, 'name' => 'Ada', 'backup' => null, 'total' => 100],
        ]]), 'orders');
    }

    public function testCoalescePreservesNonNullFalsyValuesAndNesting(): void
    {
        $tree = ClosureTranslator::translate(fn($o) => $o->name ?? $o->backup ?? 'Unknown');
        self::assertInstanceOf(CoalesceExpression::class, $tree);
        self::assertInstanceOf(CoalesceExpression::class, $tree->right);
        self::assertSame(['Guest', '', 'Ada'], $this->orders()->select($tree)->toArray());
        self::assertSame([0, 20, 100], $this->orders()->select(fn($o) => $o->total ?? 50)->toArray());
        self::assertSame([false, false, false], $this->orders()->select(fn($o) => false ?? true)->toArray());
    }

    public function testConditionalProjectionAndNestedCoalesce(): void
    {
        $label = 'large';
        $tree = ClosureTranslator::translate(fn($o) => $o->total >= 100 ? $label : ($o->name ?? 'Guest'));
        self::assertInstanceOf(ConditionalExpression::class, $tree);
        self::assertSame(['Guest', '', 'large'], $this->orders()->select($tree)->toArray());
        self::assertSame([3], $this->orders()->where(fn($o) => ($o->name ?? 'Guest') == 'Ada')
            ->select(fn($o) => $o->id)->toArray());
        $nested = fn($o) => $o->total >= 100 ? 'large' : ($o->total > 0 ? 'medium' : 'small');
        self::assertSame(['small', 'medium', 'large'], $this->orders()->select($nested)->toArray());
    }

    public function testUnselectedBranchesAreNotEvaluated(): void
    {
        self::assertSame([0, 20, 100], $this->orders()->select(fn($o) => $o->total ?? (1 / 0))->toArray());
        self::assertSame([0, 5, 1], $this->orders()->select(fn($o) => $o->total == 0 ? 0 : 100 / $o->total)->toArray());
        self::assertSame(['fallback', 'fallback', 'fallback'], $this->orders()->select(fn($o) => null ? (1 / 0) : 'fallback')->toArray());
    }

    public function testExplicitHelpersAndCorrelatedOuterBinding(): void
    {
        self::assertSame(['Guest', '', 'Ada'], $this->orders()->select(Expr::coalesce(Expr::field('name'), 'Guest'))->toArray());
        self::assertSame(['small', 'small', 'large'], $this->orders()->select(
            Expr::condition(Expr::gte(Expr::field('total'), 100), 'large', 'small')
        )->toArray());
        $orders = $this->orders();
        self::assertSame([1, 2, 3], $orders->where(fn($outer) => $orders->any(
            fn($inner) => ($inner->name ?? $outer->backup) == ($outer->total >= 100 ? 'Ada' : ($outer->backup ?? 'Guest'))
        ))->select(fn($o) => $o->id)->toArray());
    }

    public function testShortTernaryIsRejectedExplicitly(): void
    {
        $this->expectException(ClosureTranslationException::class);
        $this->expectExceptionMessage('Short ternary');
        ClosureTranslator::translate(fn($o) => $o->name ?: 'Guest');
    }
}
