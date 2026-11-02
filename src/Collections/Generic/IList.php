<?php

declare(strict_types=1);

namespace Light\Linq\Collections\Generic;

use ArrayAccess;

/** @template T @extends ICollection<T> @extends ArrayAccess<int, T> */
interface IList extends ICollection, ArrayAccess
{
    /** @return T */
    public function get(int $index): mixed;

    /** @param T $item */
    public function set(int $index, mixed $item): void;

    /** @param T $item */
    public function insert(int $index, mixed $item): void;

    public function removeAt(int $index): void;

    public function removeRange(int $index, int $count): void;

    /** @param iterable<T> $items */
    public function insertRange(int $index, iterable $items): void;

    /** @return GenericList<T> */
    public function getRange(int $index, int $count): GenericList;

    /** @param T $item */
    public function indexOf(mixed $item): int;
}
