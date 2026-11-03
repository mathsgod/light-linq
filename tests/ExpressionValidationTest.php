<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\Expr;
use Light\Linq\Expression\AggregateExpression;
use Light\Linq\Expression\BinaryExpression;
use Light\Linq\Expression\FieldExpression;
use Light\Linq\Expression\LogicalExpression;
use Light\Linq\Expression\ProjectionExpression;
use Light\Linq\Expression\SourceExpression;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExpressionValidationTest extends TestCase
{
    public static function invalidExpressions(): array
    {
        return [
            'empty source' => [fn () => new SourceExpression('')],
            'empty field' => [fn () => new FieldExpression('')],
            'unsupported binary operator' => [fn () => new BinaryExpression(Expr::value(1), 'LIKE', Expr::value(2))],
            'unsupported logical operator' => [fn () => new LogicalExpression(Expr::value(true), 'xor', Expr::value(false))],
            'unknown aggregate' => [fn () => new AggregateExpression('median', Expr::value(1))],
            'sum without operand' => [fn () => new AggregateExpression('sum')],
            'avg without operand' => [fn () => new AggregateExpression('avg')],
            'min without operand' => [fn () => new AggregateExpression('min')],
            'max without operand' => [fn () => new AggregateExpression('max')],
            'empty projection' => [fn () => new ProjectionExpression([])],
            'empty alias' => [fn () => new ProjectionExpression(['' => Expr::value(1)])],
            'numeric alias' => [fn () => new ProjectionExpression([0 => Expr::value(1)])],
            'non-expression member' => [fn () => new ProjectionExpression(['id' => 1])],
            'no fields' => [fn () => Expr::fields()],
            'duplicate final segment aliases' => [fn () => Expr::fields('customer.id', 'order.id')],
            'empty projection factory' => [fn () => Expr::projection([])],
        ];
    }

    #[DataProvider('invalidExpressions')]
    public function testInvalidExpressionsFailAtConstruction(callable $factory): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $factory();
    }
}
