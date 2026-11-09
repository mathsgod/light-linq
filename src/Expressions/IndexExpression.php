<?php

declare(strict_types=1);

namespace Light\Linq\Expressions;

/** Zero-based position in an indexed operation; depth counts enclosing any() scopes. */
final readonly class IndexExpression implements ValueExpression
{
    public function __construct(public int $depth = 0)
    {
        if ($depth < 0) {
            throw new \InvalidArgumentException('Index scope depth cannot be negative.');
        }
    }
}
