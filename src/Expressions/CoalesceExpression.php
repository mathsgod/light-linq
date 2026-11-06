<?php

declare(strict_types=1);

namespace Light\Linq\Expressions;

final readonly class CoalesceExpression implements ValueExpression
{
    public function __construct(public ValueExpression $left, public ValueExpression $right) {}
}
