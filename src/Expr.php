<?php

declare(strict_types=1);

namespace Light\Linq;

use Light\Linq\Expressions\BinaryExpression;
use Light\Linq\Expressions\ConstantExpression;
use Light\Linq\Expressions\FieldExpression;
use Light\Linq\Expressions\LogicalExpression;
use Light\Linq\Expressions\ProjectionExpression;
use Light\Linq\Expressions\ValueExpression;

final class Expr
{
    public static function index(): Expressions\IndexExpression
    {
        return new Expressions\IndexExpression();
    }

    public static function any(IQueryable $source): Expressions\AnyExpression
    {
        return new Expressions\AnyExpression($source->expression());
    }

    public static function outerField(string $path, int $depth = 1): Expressions\OuterFieldExpression
    {
        return new Expressions\OuterFieldExpression($path, $depth);
    }

    public static function contains(ValueExpression $item, IQueryable $source): Expressions\ContainsExpression
    {
        return new Expressions\ContainsExpression($item, $source->expression());
    }

    public static function not(ValueExpression $operand): Expressions\NotExpression
    {
        return new Expressions\NotExpression($operand);
    }

    public static function key(?string $member = null): Expressions\GroupKeyExpression
    {
        return new Expressions\GroupKeyExpression($member);
    }
    public static function count(?ValueExpression $operand = null): Expressions\AggregateExpression
    {
        return new Expressions\AggregateExpression('count', $operand);
    }
    public static function sum(ValueExpression $operand): Expressions\AggregateExpression
    {
        return new Expressions\AggregateExpression('sum', $operand);
    }
    public static function avg(ValueExpression $operand): Expressions\AggregateExpression
    {
        return new Expressions\AggregateExpression('avg', $operand);
    }
    public static function min(ValueExpression $operand): Expressions\AggregateExpression
    {
        return new Expressions\AggregateExpression('min', $operand);
    }
    public static function max(ValueExpression $operand): Expressions\AggregateExpression
    {
        return new Expressions\AggregateExpression('max', $operand);
    }
    public static function coalesce(ValueExpression $left, mixed $right): Expressions\CoalesceExpression
    {
        return new Expressions\CoalesceExpression($left, $right instanceof ValueExpression ? $right : self::value($right));
    }

    public static function condition(ValueExpression $test, mixed $ifTrue, mixed $ifFalse): Expressions\ConditionalExpression
    {
        return new Expressions\ConditionalExpression($test,
            $ifTrue instanceof ValueExpression ? $ifTrue : self::value($ifTrue),
            $ifFalse instanceof ValueExpression ? $ifFalse : self::value($ifFalse));
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
