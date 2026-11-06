<?php

declare(strict_types=1);

namespace Light\Linq\Expressions;

final readonly class ConditionalExpression implements ValueExpression
{
    public function __construct(public ValueExpression $test, public ValueExpression $ifTrue, public ValueExpression $ifFalse) {}
}
