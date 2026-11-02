<?php

declare(strict_types=1);

namespace PhpLinq;

use PhpLinq\Expression\BinaryExpression;
use PhpLinq\Expression\ConstantExpression;
use PhpLinq\Expression\FieldExpression;
use PhpLinq\Expression\LogicalExpression;
use PhpLinq\Expression\ProjectionExpression;
use PhpLinq\Expression\ValueExpression;

final class Expr
{
    public static function key(?string $member = null): Expression\GroupKeyExpression
    {
        return new Expression\GroupKeyExpression($member);
    }
    public static function count(?ValueExpression $operand = null): Expression\AggregateExpression
    {
        return new Expression\AggregateExpression('count', $operand);
    }
    public static function sum(ValueExpression $operand): Expression\AggregateExpression
    {
        return new Expression\AggregateExpression('sum', $operand);
    }
    public static function avg(ValueExpression $operand): Expression\AggregateExpression
    {
        return new Expression\AggregateExpression('avg', $operand);
    }
    public static function min(ValueExpression $operand): Expression\AggregateExpression
    {
        return new Expression\AggregateExpression('min', $operand);
    }
    public static function max(ValueExpression $operand): Expression\AggregateExpression
    {
        return new Expression\AggregateExpression('max', $operand);
    }
    public static function field(string $path): FieldExpression
    {
        return new FieldExpression($path);
    }

    public static function value(mixed $value): ConstantExpression
    {
        return new ConstantExpression($value);
    }

    public static function eq(ValueExpression $left, mixed $right): BinaryExpression
    {
        return self::compare($left, '=', $right);
    }

    public static function neq(ValueExpression $left, mixed $right): BinaryExpression
    {
        return self::compare($left, '!=', $right);
    }

    public static function gt(ValueExpression $left, mixed $right): BinaryExpression
    {
        return self::compare($left, '>', $right);
    }

    public static function gte(ValueExpression $left, mixed $right): BinaryExpression
    {
        return self::compare($left, '>=', $right);
    }

    public static function lt(ValueExpression $left, mixed $right): BinaryExpression
    {
        return self::compare($left, '<', $right);
    }

    public static function lte(ValueExpression $left, mixed $right): BinaryExpression
    {
        return self::compare($left, '<=', $right);
    }

    public static function both(ValueExpression $left, ValueExpression $right): LogicalExpression
    {
        return new LogicalExpression($left, 'and', $right);
    }

    public static function either(ValueExpression $left, ValueExpression $right): LogicalExpression
    {
        return new LogicalExpression($left, 'or', $right);
    }

    public static function fields(string ...$paths): ProjectionExpression
    {
        if ($paths === []) {
            throw new \InvalidArgumentException('At least one field is required.');
        }

        $members = [];
        foreach ($paths as $path) {
            $segments = explode('.', $path);
            $alias = end($segments);
            if (array_key_exists($alias, $members)) {
                throw new \InvalidArgumentException("Duplicate projection alias: {$alias}");
            }
            $members[$alias] = self::field($path);
        }
        return new ProjectionExpression($members);
    }

    /**
     * @param non-empty-array<string, ValueExpression|string> $members
     */
    public static function projection(array $members): ProjectionExpression
    {
        $expressions = [];
        foreach ($members as $alias => $expression) {
            $expressions[$alias] = is_string($expression)
                ? self::field($expression)
                : $expression;
        }
        return new ProjectionExpression($expressions);
    }

    private static function compare(ValueExpression $left, string $operator, mixed $right): BinaryExpression
    {
        return new BinaryExpression(
            $left,
            $operator,
            $right instanceof ValueExpression ? $right : self::value($right),
        );
    }
}
