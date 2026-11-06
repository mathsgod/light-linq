<?php

declare(strict_types=1);

namespace Light\Linq\Expressions;

/** The query operation source.Any(), independent of its provider translation. */
final readonly class AnyExpression implements ValueExpression
{
    public function __construct(public Expression $source) {}
}
