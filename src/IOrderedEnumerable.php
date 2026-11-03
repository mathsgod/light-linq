<?php

declare(strict_types=1);

namespace Light\Linq;

/** @template T @extends IEnumerable<T> */
interface IOrderedEnumerable extends IEnumerable
{
    /**
     * @param callable(T): mixed $keySelector
     * @param (callable(mixed, mixed): int)|null $comparer Compares selected keys.
     * @return IOrderedEnumerable<T>
     */
    public function thenBy(callable $keySelector, ?callable $comparer = null): IOrderedEnumerable;

    /**
     * @param callable(T): mixed $keySelector
     * @param (callable(mixed, mixed): int)|null $comparer Compares selected keys.
     * @return IOrderedEnumerable<T>
     */
    public function thenByDescending(callable $keySelector, ?callable $comparer = null): IOrderedEnumerable;
}
