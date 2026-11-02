<?php

declare(strict_types=1);

namespace Light\Linq\Collections\Generic;

/** @template T @extends ICollection<T> */
interface ISet extends ICollection
{
    /** @param T $item */
    public function tryAdd(mixed $item): bool;

    /** @param iterable<T> $other */
    public function unionWith(iterable $other): void;

    /** @param iterable<T> $other */
    public function intersectWith(iterable $other): void;

    /** @param iterable<T> $other */
    public function exceptWith(iterable $other): void;

    /** @param iterable<T> $other */
    public function symmetricExceptWith(iterable $other): void;

    /** @param iterable<T> $other */
    public function overlaps(iterable $other): bool;

    /** @param iterable<T> $other */
    public function setEquals(iterable $other): bool;

    /** @param iterable<T> $other */
    public function isSubsetOf(iterable $other): bool;

    /** @param iterable<T> $other */
    public function isSupersetOf(iterable $other): bool;
}
