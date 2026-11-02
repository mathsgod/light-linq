<?php

declare(strict_types=1);

namespace Light\Linq;

/** @template T @extends IEnumerable<T> */
interface IOrderedEnumerable extends IEnumerable
{
    /** @param callable(T): mixed $keySelector @return IOrderedEnumerable<T> */
    public function thenBy(callable $keySelector): IOrderedEnumerable;

    /** @param callable(T): mixed $keySelector @return IOrderedEnumerable<T> */
    public function thenByDescending(callable $keySelector): IOrderedEnumerable;
}
