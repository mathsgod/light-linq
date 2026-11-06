<?php

declare(strict_types=1);

namespace Light\Linq\Expressions;

/** The query operation source.Contains(item), independent of its provider translation. */
final readonly class ContainsExpression implements ValueExpression
{
    public function __construct(public ValueExpression $item, public Expression $source) {}
}
