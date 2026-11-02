<?php

declare(strict_types=1);

namespace Light\Linq\Expression;

final readonly class FieldExpression implements ValueExpression
{
    public function __construct(public string $path)
    {
        if ($path === '') {
            throw new \InvalidArgumentException('A field path cannot be empty.');
        }
    }
}
