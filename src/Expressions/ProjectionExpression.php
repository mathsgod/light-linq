<?php

declare(strict_types=1);

namespace Light\Linq\Expressions;

final readonly class ProjectionExpression implements ValueExpression
{
    /** @param non-empty-array<string, ValueExpression> $members */
    public function __construct(public array $members)
    {
        if ($members === []) {
            throw new \InvalidArgumentException('A projection must contain at least one member.');
        }
        foreach ($members as $alias => $expression) {
            if (!is_string($alias) || $alias === '') {
                throw new \InvalidArgumentException('Projection aliases must be non-empty strings.');
            }
            if (!$expression instanceof ValueExpression) {
                throw new \InvalidArgumentException('Projection members must be value expressions.');
            }
        }
    }
}
