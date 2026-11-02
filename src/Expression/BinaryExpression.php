<?php

declare(strict_types=1);

namespace Light\Linq\Expression;

final readonly class BinaryExpression implements ValueExpression
{
    public function __construct(
        public ValueExpression $left,
        public string $operator,
        public ValueExpression $right,
    ) {
        if (!in_array($operator, ['=', '!=', '>', '>=', '<', '<='], true)) {
            throw new \InvalidArgumentException("Unsupported binary operator: {$operator}");
        }
    }
}
