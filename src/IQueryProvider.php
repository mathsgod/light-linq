<?php

declare(strict_types=1);

namespace Light\Linq;

use Light\Linq\Expressions\Expression;

interface IQueryProvider
{
    public function execute(Expression $expression): mixed;
}
