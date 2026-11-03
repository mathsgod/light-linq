<?php

declare(strict_types=1);

namespace Light\Linq;

use Countable;
use IteratorAggregate;
use Light\Collections\Generic\Dictionary;
use Light\Collections\Generic\EqualityComparer;
use Light\Collections\Generic\GenericList;
use Light\Collections\Generic\HashSet;
use Light\Collections\Generic\KeyValuePair;
use Traversable;

/** @template T @extends IteratorAggregate<int, T> */
interface IEnumerable extends IteratorAggregate, Countable
{
    /** @return IEnumerable<array{index: int, value: T}> */
    public function index(): IEnumerable;

    /** @template TResult @param callable(T): TResult $selector @return IEnumerable<TResult> */
    public function select(callable $selector): IEnumerable;

    /** @param callable(T): bool $predicate @return IEnumerable<T> */
    public function where(callable $predicate): IEnumerable;

    /** @template TResult @param callable(T): iterable<TResult> $selector @return IEnumerable<TResult> */
    public function selectMany(callable $selector): IEnumerable;

    /**
     * @template TKey
     * @param callable(T): TKey $keySelector
     * @param EqualityComparer<TKey>|null $comparer
     * @return IEnumerable<Grouping>
     */
    public function groupBy(callable $keySelector, ?EqualityComparer $comparer = null): IEnumerable;

    /**
     * @template TInner
     * @template TKey
     * @template TResult
     * @param iterable<TInner> $inner
     * @param callable(T): TKey $outerKeySelector
     * @param callable(TInner): TKey $innerKeySelector
     * @param callable(T, IEnumerable<TInner>): TResult $resultSelector
     * @param EqualityComparer<TKey>|null $comparer
     * @return IEnumerable<TResult>
     */
    public function groupJoin(
        iterable $inner,
        callable $outerKeySelector,
        callable $innerKeySelector,
        callable $resultSelector,
        ?EqualityComparer $comparer = null,
    ): IEnumerable;

    /**
     * @param callable(T): mixed $keySelector
     * @param (callable(mixed, mixed): int)|null $comparer Compares selected keys.
     * @return IOrderedEnumerable<T>
     */
    public function orderBy(callable $keySelector, ?callable $comparer = null): IOrderedEnumerable;

    /**
     * @param callable(T): mixed $keySelector
     * @param (callable(mixed, mixed): int)|null $comparer Compares selected keys.
     * @return IOrderedEnumerable<T>
     */
    public function orderByDescending(callable $keySelector, ?callable $comparer = null): IOrderedEnumerable;

    /** @return IEnumerable<T> */
    public function skip(int $count): IEnumerable;

    /** @return IEnumerable<T> */
    public function take(int $count): IEnumerable;

    /** @param callable(T): bool $predicate @return IEnumerable<T> */
    public function skipWhile(callable $predicate): IEnumerable;

    /** @param callable(T): bool $predicate @return IEnumerable<T> */
    public function takeWhile(callable $predicate): IEnumerable;

    /** @return IEnumerable<T> */
    public function skipLast(int $count): IEnumerable;

    /** @return IEnumerable<T> */
    public function takeLast(int $count): IEnumerable;

    /** @param (callable(T): mixed)|null $keySelector @return IEnumerable<T> */
    public function distinct(?callable $keySelector = null): IEnumerable;

    /**
     * @template TKey
     * @param callable(T): TKey $keySelector
     * @param EqualityComparer<TKey>|null $comparer
     * @return IEnumerable<T>
     */
    public function distinctBy(callable $keySelector, ?EqualityComparer $comparer = null): IEnumerable;

    /** @param T $value @return IEnumerable<T> */
    public function append(mixed $value): IEnumerable;

    /** @param T $value @return IEnumerable<T> */
    public function prepend(mixed $value): IEnumerable;

    /** @param iterable<T> $other @return IEnumerable<T> */
    public function concat(iterable $other): IEnumerable;

    /** @param T|null $default @return IEnumerable<T|null> */
    public function defaultIfEmpty(mixed $default = null): IEnumerable;

    /**
     * @template TSecond
     * @template TResult
     * @param iterable<TSecond> $second
     * @param callable(T, TSecond): TResult $resultSelector
     * @return IEnumerable<TResult>
     */
    public function zip(iterable $second, callable $resultSelector): IEnumerable;

    /** @param iterable<T> $second @param EqualityComparer<T>|null $comparer */
    public function sequenceEqual(iterable $second, ?EqualityComparer $comparer = null): bool;

    /** @return T @throws \OutOfBoundsException */
    public function elementAt(int $index): mixed;

    /** @param T|null $default @return T|null */
    public function elementAtOrDefault(int $index, mixed $default = null): mixed;

    /** @return IEnumerable<T> */
    public function reverse(): IEnumerable;

    /** @return IEnumerable<list<T>> */
    public function chunk(int $size): IEnumerable;

    /** @param callable(T): bool|null $predicate */
    public function count(?callable $predicate = null): int;

    /** @param callable(T): bool|null $predicate */
    public function any(?callable $predicate = null): bool;

    /** @param callable(T): bool $predicate */
    public function all(callable $predicate): bool;

    /** @param T $value @param EqualityComparer<T>|null $comparer */
    public function contains(mixed $value, ?EqualityComparer $comparer = null): bool;

    /** @param callable(T): bool|null $predicate @return T */
    public function first(?callable $predicate = null): mixed;

    /** @param callable(T): bool|null $predicate @return T|null */
    public function firstOrDefault(?callable $predicate = null, mixed $default = null): mixed;

    /** @param callable(T): bool|null $predicate @return T */
    public function last(?callable $predicate = null): mixed;

    /** @param callable(T): bool|null $predicate @return T|null */
    public function lastOrDefault(?callable $predicate = null, mixed $default = null): mixed;

    /** @param callable(T): bool|null $predicate @return T */
    public function single(?callable $predicate = null): mixed;

    /** @param callable(T): bool|null $predicate @return T|null */
    public function singleOrDefault(?callable $predicate = null, mixed $default = null): mixed;

    /** @param (callable(T): int|float)|null $selector */
    public function sum(?callable $selector = null): int|float;

    /** @param (callable(T): int|float)|null $selector */
    public function average(?callable $selector = null): int|float;

    /** @param (callable(T): mixed)|null $selector */
    public function min(?callable $selector = null, ?callable $comparer = null): mixed;

    /** @param (callable(T): mixed)|null $selector */
    public function max(?callable $selector = null, ?callable $comparer = null): mixed;

    /** @param callable(T): mixed $keySelector @return T */
    public function minBy(callable $keySelector, ?callable $comparer = null): mixed;

    /** @param callable(T): mixed $keySelector @return T */
    public function maxBy(callable $keySelector, ?callable $comparer = null): mixed;

    /** @param iterable<T> $second @return IEnumerable<T> */
    public function except(iterable $second, ?EqualityComparer $comparer = null): IEnumerable;

    /** @param iterable<T> $second @return IEnumerable<T> */
    public function intersect(iterable $second, ?EqualityComparer $comparer = null): IEnumerable;

    /** @param iterable<T> $second @return IEnumerable<T> */
    public function union(iterable $second, ?EqualityComparer $comparer = null): IEnumerable;

    /**
     * @template TKey
     * @param iterable<TKey> $second Keys to exclude.
     * @param callable(T): TKey $keySelector
     * @param EqualityComparer<TKey>|null $comparer
     * @return IEnumerable<T>
     */
    public function exceptBy(iterable $second, callable $keySelector, ?EqualityComparer $comparer = null): IEnumerable;

    /**
     * @template TKey
     * @param iterable<TKey> $second Keys to retain.
     * @param callable(T): TKey $keySelector
     * @param EqualityComparer<TKey>|null $comparer
     * @return IEnumerable<T>
     */
    public function intersectBy(iterable $second, callable $keySelector, ?EqualityComparer $comparer = null): IEnumerable;

    /**
     * @template TKey
     * @param iterable<T> $second Elements to combine.
     * @param callable(T): TKey $keySelector
     * @param EqualityComparer<TKey>|null $comparer
     * @return IEnumerable<T>
     */
    public function unionBy(iterable $second, callable $keySelector, ?EqualityComparer $comparer = null): IEnumerable;

    /**
     * @template TKey
     * @param callable(T): TKey $keySelector
     * @param EqualityComparer<TKey>|null $comparer
     * @return IEnumerable<KeyValuePair<TKey, int>>
     */
    public function countBy(callable $keySelector, ?EqualityComparer $comparer = null): IEnumerable;

    /**
     * @template TKey
     * @template TAccumulate
     * @param callable(T): TKey $keySelector
     * @param TAccumulate $seed
     * @param callable(TAccumulate, T): TAccumulate $accumulator
     * @param EqualityComparer<TKey>|null $comparer
     * @return IEnumerable<KeyValuePair<TKey, TAccumulate>>
     */
    public function aggregateBy(
        callable $keySelector,
        mixed $seed,
        callable $accumulator,
        ?EqualityComparer $comparer = null,
    ): IEnumerable;

    /** @return IEnumerable<T> */
    public function shuffle(): IEnumerable;

    /**
     * @template TInner
     * @template TKey
     * @template TResult
     * @param iterable<TInner> $inner
     * @param callable(T): TKey $outerKeySelector
     * @param callable(TInner): TKey $innerKeySelector
     * @param callable(T, TInner): TResult $resultSelector
     * @param EqualityComparer<TKey>|null $comparer
     * @return IEnumerable<TResult>
     */
    public function join(
        iterable $inner,
        callable $outerKeySelector,
        callable $innerKeySelector,
        callable $resultSelector,
        ?EqualityComparer $comparer = null,
    ): IEnumerable;

    /**
     * @template TInner
     * @template TKey
     * @template TResult
     * @param iterable<TInner> $inner
     * @param callable(T): TKey $outerKeySelector
     * @param callable(TInner): TKey $innerKeySelector
     * @param callable(T, TInner|null): TResult $resultSelector
     * @param EqualityComparer<TKey>|null $comparer
     * @return IEnumerable<TResult>
     */
    public function leftJoin(
        iterable $inner,
        callable $outerKeySelector,
        callable $innerKeySelector,
        callable $resultSelector,
        ?EqualityComparer $comparer = null,
    ): IEnumerable;

    /**
     * @template TInner
     * @template TKey
     * @template TResult
     * @param iterable<TInner> $inner
     * @param callable(T): TKey $outerKeySelector
     * @param callable(TInner): TKey $innerKeySelector
     * @param callable(T|null, TInner): TResult $resultSelector
     * @param EqualityComparer<TKey>|null $comparer
     * @return IEnumerable<TResult>
     */
    public function rightJoin(
        iterable $inner,
        callable $outerKeySelector,
        callable $innerKeySelector,
        callable $resultSelector,
        ?EqualityComparer $comparer = null,
    ): IEnumerable;

    /** @param callable(mixed, T): mixed $accumulator */
    public function aggregate(callable $accumulator, mixed $seed = null): mixed;

    /** @return GenericList<T> */
    public function toList(): GenericList;

    /** @param EqualityComparer<T>|null $comparer @return HashSet<T> */
    public function toHashSet(?EqualityComparer $comparer = null): HashSet;

    /**
     * @template TKey
     * @template TValue
     * @param callable(T): TKey $keySelector
     * @param (callable(T): TValue)|null $elementSelector Defaults to the source element.
     * @param EqualityComparer<TKey>|null $comparer
     * @return Dictionary<TKey, TValue|T>
     * @throws \InvalidArgumentException If a selected key is null or duplicated.
     */
    public function toDictionary(
        callable $keySelector,
        ?callable $elementSelector = null,
        ?EqualityComparer $comparer = null,
    ): Dictionary;

    /**
     * @template TKey
     * @template TElement
     * @param callable(T): TKey $keySelector
     * @param (callable(T): TElement)|null $elementSelector Defaults to the source element.
     * @param EqualityComparer<TKey>|null $comparer
     * @return Lookup<TKey, TElement|T>
     */
    public function toLookup(
        callable $keySelector,
        ?callable $elementSelector = null,
        ?EqualityComparer $comparer = null,
    ): Lookup;

    /** @return list<T> */
    public function toArray(): array;

    /** @return Traversable<int, T> */
    public function getIterator(): Traversable;
}
