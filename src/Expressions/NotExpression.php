<?php

declare(strict_types=1);

namespace Light\Linq\Expressions;

final readonly class NotExpression implements ValueExpression
{
    public function __construct(public ValueExpression $operand) {}
}
