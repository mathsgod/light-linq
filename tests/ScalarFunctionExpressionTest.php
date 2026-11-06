<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\{ClosureTranslator, ClosureTranslationException, InMemoryQueryProvider, Queryable};
use Light\Linq\Expressions\{ConstantExpression, FieldExpression, ScalarFunctionExpression};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScalarFunctionExpressionTest extends TestCase
{
    private function rows(): Queryable
    {
        return Queryable::from(new InMemoryQueryProvider(['rows' => [
            (object) ['id' => 1, 'name' => "\t Alice \r\n", 'amount' => -12.345],
            (object) ['id' => 2, 'name' => 'é中😀 ', 'amount' => 125.0],
            (object) ['id' => 3, 'name' => null, 'amount' => null],
            (object) ['id' => 4, 'name' => '', 'amount' => -2.5],
        ]]), 'rows');
    }

    public function testNestedStringConversionsFilteringAndUtf8ByteLength(): void
    {
        self::assertSame(['alice', 'é中😀', null, ''], $this->rows()->select(fn($r) => strtolower(trim($r->name)))->toArray());
        self::assertSame(["\t ALICE \r\n", 'é中😀 ', null, ''], $this->rows()->select(fn($r) => strtoupper($r->name))->toArray());
        self::assertSame([10, 10, null, 0], $this->rows()->select(fn($r) => strlen($r->name))->toArray());
        self::assertSame([1], $this->rows()->where(fn($r) => strtolower(trim($r->name)) == 'alice')
            ->select(fn($r) => $r->id)->toArray());
        self::assertSame([5, 5, 5, 5], $this->rows()->select(fn($r) => strlen("A\0中"))->toArray());
    }

    public function testNumericFunctionsRoundDefaultsNegativePrecisionAndCaptures(): void
    {
        $precision = 2;
        self::assertSame([12.35, 125.0, null, 2.5], $this->rows()->select(fn($r) => round(abs($r->amount), $precision))->toArray());
        self::assertSame([-12.0, 125.0, null, -3.0], $this->rows()->select(fn($r) => round($r->amount))->toArray());
        self::assertSame([-10.0, 130.0, null, 0.0], $this->rows()->select(fn($r) => round($r->amount, -1))->toArray());
        self::assertSame([0, 0, 0, 0], $this->rows()->select(fn($r) => abs(0))->toArray());
    }

    public function testTreeAndExplicitConstruction(): void
    {
        $tree = ClosureTranslator::translate(fn($r) => \strtoupper($r->name));
        self::assertInstanceOf(ScalarFunctionExpression::class, $tree);
        self::assertSame('strtoupper', $tree->function);
        self::assertInstanceOf(FieldExpression::class, $tree->arguments[0]);
        self::assertSame([12.345, 125.0, null, 2.5], $this->rows()->select(
            new ScalarFunctionExpression('abs', [new FieldExpression('amount')])
        )->toArray());
    }

    public function testCorrelatedOuterFieldsAreBoundThroughFunctionArguments(): void
    {
        $rows = $this->rows();
        self::assertSame([1, 2], $rows->where(fn($outer) => $rows->any(
            fn($inner) => $inner->id == $outer->id && strlen($outer->name) > 0
        ))->select(fn($r) => $r->id)->toArray());
    }

    public static function unsupportedCalls(): iterable
    {
        yield 'named argument' => [fn($r) => strlen(string: $r->name)];
        yield 'unpacked argument' => [fn($r) => strlen(...[$r->name])];
        yield 'missing argument' => [fn($r) => abs()];
        yield 'extra unary argument' => [fn($r) => strtolower($r->name, 'extra')];
        yield 'custom trim mask' => [fn($r) => trim($r->name, 'x')];
        yield 'round mode' => [fn($r) => round($r->amount, 2, 1)];
        yield 'dynamic precision' => [fn($r) => round($r->amount, $r->id)];
        yield 'noninteger precision' => [fn($r) => round($r->amount, 2.5)];
        yield 'unlisted call' => [fn($r) => strrev($r->name)];
    }

    #[DataProvider('unsupportedCalls')]
    public function testUnsupportedSyntaxFailsDuringTranslation(\Closure $closure): void
    {
        $this->expectException(ClosureTranslationException::class);
        ClosureTranslator::translate($closure);
    }

    public function testArbitraryFunctionsCannotBeConstructed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ScalarFunctionExpression('system', [new ConstantExpression('anything')]);
    }
}
