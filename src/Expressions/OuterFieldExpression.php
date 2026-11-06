<?php

declare(strict_types=1);

namespace Light\Linq\Expressions;

/** A field in an enclosing AnyExpression scope: depth 1 is the immediately enclosing row. */
final readonly class OuterFieldExpression implements ValueExpression
{
    public function __construct(public string $path, public int $depth = 1)
    {
        new FieldExpression($path);
        if ($depth < 1) { throw new \InvalidArgumentException('Outer field depth must be positive.'); }
    }
}
