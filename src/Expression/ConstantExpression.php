<?php

declare(strict_types=1);

namespace Light\Linq\Expression;

final readonly class ConstantExpression implements ValueExpression
{
    public function __construct(public mixed $value)
    {
    }
}
