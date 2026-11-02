<?php

declare(strict_types=1);

namespace Light\Linq\Expression;

final readonly class SourceExpression implements Expression
{
    public function __construct(public string $name)
    {
        if ($name === '') {
            throw new \InvalidArgumentException('A source name cannot be empty.');
        }
    }
}
