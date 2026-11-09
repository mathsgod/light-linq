<?php

declare(strict_types=1);

namespace Light\Linq;

use IteratorAggregate;
use Light\Linq\Expressions\Expression;
use Light\Linq\Expressions\ValueExpression;
use Traversable;

/** @template T @extends IteratorAggregate<int, T> */
interface IQueryable extends IteratorAggregate
{
    public function expression(): Expression;

    public function provider(): IQueryProvider;

    public function groupBy(ValueExpression|\Closure $keySelector): self;
    public function distinct(): self;
    public function distinctBy(ValueExpression|\Closure $keySelector): self;
    public function countBy(ValueExpression|\Closure $keySelector): self;
    /** The second query must select keys, not complete rows. */
    public function exceptBy(IQueryable $second, ValueExpression|\Closure $keySelector): self;
    /** The second query must select keys, not complete rows. */
    public function intersectBy(IQueryable $second, ValueExpression|\Closure $keySelector): self;
    /** The second query supplies complete elements using the same key selector. */
    public function unionBy(IQueryable $second, ValueExpression|\Closure $keySelector): self;
    public function minBy(ValueExpression|\Closure $keySelector): mixed;
    public function maxBy(ValueExpression|\Closure $keySelector): mixed;
    public function where(ValueExpression|\Closure $predicate): self;

    public function select(ValueExpression|\Closure $selector): self;

    public function concat(IQueryable $second): self;
    public function union(IQueryable $second): self;
    public function intersect(IQueryable $second): self;
    public function except(IQueryable $second): self;

    /** Result and predicate fields use outer.* and inner.*. */
    public function selectMany(
        IQueryable|ValueExpression|\Closure $inner,
        ?ValueExpression $resultSelector = null,
        ?ValueExpression $predicate = null,
    ): self;

    public function join(
        IQueryable $inner,
        ValueExpression|\Closure $outerKey,
        ValueExpression|\Closure $innerKey,
        ValueExpression $resultSelector,
    ): self;

    public function leftJoin(
        IQueryable $inner,
        ValueExpression|\Closure $outerKey,
        ValueExpression|\Closure $innerKey,
        ValueExpression $resultSelector,
    ): self;

    /** Preserve the inner (right) source; unmatched outer fields are null. */
    public function rightJoin(
        IQueryable $inner,
        ValueExpression|\Closure $outerKey,
        ValueExpression|\Closure $innerKey,
        ValueExpression $resultSelector,
    ): self;

    /** Result fields use outer.* and inner (an IEnumerable of matching rows). */
    public function groupJoin(
        IQueryable $inner,
        ValueExpression|\Closure $outerKey,
        ValueExpression|\Closure $innerKey,
        ValueExpression $resultSelector,
    ): self;

    /** Pair rows by enumeration position; result fields use outer.* and inner.*. */
    public function zip(IQueryable $second, ValueExpression $resultSelector): self;

    /** @return IQueryable<list<T>> */
    public function chunk(int $size): self;

    public function orderBy(ValueExpression|\Closure $keySelector): self;

    public function order(): self;
    public function orderDescending(): self;

    public function orderByDescending(ValueExpression|\Closure $keySelector): self;

    public function thenBy(ValueExpression|\Closure $keySelector): self;
    public function thenByDescending(ValueExpression|\Closure $keySelector): self;
    public function firstOrDefault(ValueExpression|\Closure|null $predicate = null, mixed $default = null): mixed;
    public function elementAt(int $index): mixed;
    public function elementAtOrDefault(int $index, mixed $default = null): mixed;
    public function single(ValueExpression|\Closure|null $predicate = null): mixed;
    public function singleOrDefault(ValueExpression|\Closure|null $predicate = null, mixed $default = null): mixed;
    public function last(ValueExpression|\Closure|null $predicate = null): mixed;
    public function lastOrDefault(ValueExpression|\Closure|null $predicate = null, mixed $default = null): mixed;
    public function all(ValueExpression|\Closure $predicate): bool;
    public function contains(mixed $value): bool;
    public function skip(int $count): self;

    public function take(int $count): self;

    public function append(mixed $value): self;
    public function prepend(mixed $value): self;
    public function reverse(): self;
    public function defaultIfEmpty(mixed $default = null): self;
    public function skipWhile(ValueExpression|\Closure $predicate): self;
    public function takeWhile(ValueExpression|\Closure $predicate): self;
    public function skipLast(int $count): self;
    public function takeLast(int $count): self;

    /** Numeric strings from SQL decimal aggregates retain their precision. */
    public function sum(ValueExpression|\Closure $selector): int|float|string;
    public function average(ValueExpression|\Closure $selector): int|float|string|null;
    public function min(ValueExpression|\Closure $selector): mixed;
    public function max(ValueExpression|\Closure $selector): mixed;

    public function count(): int;

    public function longCount(ValueExpression|\Closure|null $predicate = null): int;

    public function any(ValueExpression|\Closure|null $predicate = null): bool;

    public function first(ValueExpression|\Closure|null $predicate = null): mixed;

    /** @return IEnumerable<T> */
    public function asEnumerable(): IEnumerable;

    /** @return list<T> */
    public function toArray(): array;

    /** @return Traversable<int, T> */
    public function getIterator(): Traversable;
}
