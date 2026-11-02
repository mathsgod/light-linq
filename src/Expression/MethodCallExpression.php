<?php

declare(strict_types=1);

namespace Light\Linq\Expression;

final readonly class MethodCallExpression implements Expression
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public Expression $source,
        public string $method,
        public array $arguments = [],
    ) {
    }
}
