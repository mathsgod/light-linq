<?php

declare(strict_types=1);

namespace Light\Linq\Collections\Generic;

use Countable;
use IteratorAggregate;

/**
 * @template T
 * @extends IteratorAggregate<int, T>
 */
interface IReadOnlyCollection extends IteratorAggregate, Countable
{
    public function count(): int;

    /** @return list<T> */
    public function toArray(): array;
}
