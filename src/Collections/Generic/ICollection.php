<?php

declare(strict_types=1);

namespace Light\Linq\Collections\Generic;

/** @template T @extends IReadOnlyCollection<T> */
interface ICollection extends IReadOnlyCollection
{
    /** @param T $item */
    public function add(mixed $item): void;

    /** @param T $item */
    public function remove(mixed $item): bool;

    /** @param T $item */
    public function contains(mixed $item): bool;

    public function clear(): void;

    public function isEmpty(): bool;
}
