<?php

declare(strict_types=1);

namespace Light\Linq\Expression;

final readonly class LogicalExpression implements ValueExpression
{
    public function __construct(
        public ValueExpression $left,
        public string $operator,
        public ValueExpression $right,
    ) {
        if (!in_array($operator, ['and', 'or'], true)) {
            throw new \InvalidArgumentException("Unsupported logical operator: {$operator}");
        }
    }
}
