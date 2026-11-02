<?php

declare(strict_types=1);

namespace PhpLinq;

use IteratorAggregate;
use PhpLinq\Expression\Expression;
use PhpLinq\Expression\ValueExpression;
use Traversable;

/** @template T @extends IteratorAggregate<int, T> */
interface IQueryable extends IteratorAggregate
{
    public function expression(): Expression;

    public function provider(): IQueryProvider;

    public function groupBy(ValueExpression $keySelector): self;
    public function distinct(): self;
    public function where(ValueExpression $predicate): self;

    public function select(ValueExpression $selector): self;

    public function join(
        IQueryable $inner,
        ValueExpression $outerKey,
        ValueExpression $innerKey,
        ValueExpression $resultSelector,
    ): self;

    public function leftJoin(
        IQueryable $inner,
        ValueExpression $outerKey,
        ValueExpression $innerKey,
        ValueExpression $resultSelector,
    ): self;

    public function orderBy(ValueExpression $keySelector): self;

    public function orderByDescending(ValueExpression $keySelector): self;

    public function skip(int $count): self;

    public function take(int $count): self;

    public function count(): int;

    public function any(?ValueExpression $predicate = null): bool;

    public function first(?ValueExpression $predicate = null): mixed;

    /** @return IEnumerable<T> */
    public function asEnumerable(): IEnumerable;

    /** @return list<T> */
    public function toArray(): array;

    /** @return Traversable<int, T> */
    public function getIterator(): Traversable;
}
