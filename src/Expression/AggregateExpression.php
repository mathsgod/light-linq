<?php
declare(strict_types=1);
namespace Light\Linq\Expression;
final readonly class AggregateExpression implements ValueExpression
{
    public function __construct(public string $function, public ?ValueExpression $operand = null)
    {
        if (!in_array($function, ['count', 'sum', 'avg', 'min', 'max'], true)
            || ($function !== 'count' && $operand === null)) {
            throw new \InvalidArgumentException('Invalid aggregate expression.');
        }
    }
}
