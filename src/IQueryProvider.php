<?php

declare(strict_types=1);

namespace Light\Linq;

use Light\Linq\Expression\Expression;

interface IQueryProvider
{
    public function execute(Expression $expression): mixed;
}
