<?php

declare(strict_types=1);

namespace Light\Linq\Expressions;

/** Literal string matching, independent of SQL wildcard syntax. */
final readonly class StringMatchExpression implements ValueExpression
{
    public function __construct(
        public ValueExpression $subject,
        public string $operation,
        public ValueExpression $needle,
    ) {
        if (!in_array($operation, ['startsWith', 'endsWith', 'contains'], true)) {
            throw new \InvalidArgumentException('Unsupported string match operation: '.$operation);
        }
    }
}
