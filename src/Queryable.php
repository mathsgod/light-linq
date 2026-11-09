<?php

declare(strict_types=1);

namespace Light\Linq;

use Light\Linq\Expressions\Expression;
use Light\Linq\Expressions\MethodCallExpression;
use Light\Linq\Expressions\SourceExpression;
use Light\Linq\Expressions\ValueExpression;
use Traversable;

/** @template T @implements IQueryable<T> */
final readonly class Queryable implements IQueryable
{
    public function __construct(
        private IQueryProvider $queryProvider,
        private Expression $queryExpression,
    ) {
    }

    public static function from(IQueryProvider $provider, string $source): self
    {
        return new self($provider, new SourceExpression($source));
    }

    public static function asQueryable(iterable $source): IQueryable
    {
        if ($source instanceof IQueryable) {
            return $source;
        }
        return self::from(new InMemoryQueryProvider(['source' => $source]), 'source');
    }

    public function aggregate(ValueExpression|\Closure $accumulator, mixed $seed = null, ValueExpression|\Closure|null $resultSelector = null): mixed
    {
        $accumulator = $accumulator instanceof \Closure
            ? ClosureTranslator::translate($accumulator, $this->queryProvider, parameterPaths: ['accumulator', 'item']) : $accumulator;
        $resultSelector = $resultSelector instanceof \Closure
            ? ClosureTranslator::translate($resultSelector, $this->queryProvider, parameterPaths: ['result']) : $resultSelector;
        return $this->queryProvider->execute(new MethodCallExpression($this->queryExpression, 'aggregate', [
            'accumulator' => $accumulator, 'seed' => $seed, 'hasSeed' => func_num_args() >= 2,
            'resultSelector' => $resultSelector,
        ]));
    }

    public function aggregateBy(ValueExpression|\Closure $keySelector, mixed $seed, ValueExpression|\Closure $accumulator): self
    {
        $keySelector = $keySelector instanceof \Closure ? ClosureTranslator::translate($keySelector, $this->queryProvider) : $keySelector;
        $accumulator = $accumulator instanceof \Closure
            ? ClosureTranslator::translate($accumulator, $this->queryProvider, parameterPaths: ['accumulator', 'item']) : $accumulator;
        return $this->call('aggregateBy', ['keySelector' => $keySelector, 'seed' => $seed, 'accumulator' => $accumulator]);
    }

    public function expression(): Expression
    {
        return $this->queryExpression;
    }

    public function provider(): IQueryProvider
    {
        return $this->queryProvider;
    }

    public function groupBy(ValueExpression|\Closure $keySelector): self
    {
        $keySelector = $keySelector instanceof \Closure ? ClosureTranslator::translate($keySelector, $this->queryProvider) : $keySelector;
        return $this->call('groupBy', ['keySelector' => $keySelector]);
    }
    public function distinct(): self
    {
        return $this->call('distinct', []);
    }

    public function distinctBy(ValueExpression|\Closure $keySelector): self
    {
        return $this->keyQuery('distinctBy', $keySelector);
    }

    public function countBy(ValueExpression|\Closure $keySelector): self
    {
        return $this->keyQuery('countBy', $keySelector);
    }

    public function exceptBy(IQueryable $second, ValueExpression|\Closure $keySelector): self
    {
        return $this->keyQuery('exceptBy', $keySelector, $second);
    }

    public function intersectBy(IQueryable $second, ValueExpression|\Closure $keySelector): self
    {
        return $this->keyQuery('intersectBy', $keySelector, $second);
    }

    public function unionBy(IQueryable $second, ValueExpression|\Closure $keySelector): self
    {
        return $this->keyQuery('unionBy', $keySelector, $second);
    }

    public function minBy(ValueExpression|\Closure $keySelector): mixed
    {
        return $this->queryProvider->execute($this->keyQuery('minBy', $keySelector)->expression());
    }

    public function maxBy(ValueExpression|\Closure $keySelector): mixed
    {
        return $this->queryProvider->execute($this->keyQuery('maxBy', $keySelector)->expression());
    }

    private function keyQuery(string $method, ValueExpression|\Closure $keySelector, ?IQueryable $second = null): self
    {
        if ($second !== null && $second->provider() !== $this->queryProvider) {
            throw new \InvalidArgumentException('Key-based set queries must use the same provider instance.');
        }
        $keySelector = $keySelector instanceof \Closure ? ClosureTranslator::translate($keySelector, $this->queryProvider) : $keySelector;
        $arguments = ['keySelector' => $keySelector];
        if ($second !== null) {
            $arguments['second'] = $second->expression();
        }
        return $this->call($method, $arguments);
    }
    public function where(ValueExpression|\Closure $predicate): self
    {
        $predicate = $predicate instanceof \Closure ? ClosureTranslator::translate($predicate, $this->queryProvider, true) : $predicate;
        return $this->call('where', ['predicate' => $predicate]);
    }

    public function select(ValueExpression|\Closure $selector): self
    {
        $selector = $selector instanceof \Closure ? ClosureTranslator::translate($selector, $this->queryProvider, true) : $selector;
        return $this->call('select', ['selector' => $selector]);
    }

    public function concat(IQueryable $second): self
    {
        return $this->setQuery('concat', $second);
    }

    public function union(IQueryable $second): self
    {
        return $this->setQuery('union', $second);
    }

    public function intersect(IQueryable $second): self
    {
        return $this->setQuery('intersect', $second);
    }

    public function except(IQueryable $second): self
    {
        return $this->setQuery('except', $second);
    }

    /** Flatten another query, optionally matching each outer/inner pair. */
    public function selectMany(
        IQueryable|ValueExpression|\Closure $inner,
        ?ValueExpression $resultSelector = null,
        ?ValueExpression $predicate = null,
    ): self {
        if (!$inner instanceof IQueryable) {
            $selector = $inner instanceof \Closure ? ClosureTranslator::translate($inner, $this->queryProvider, true) : $inner;
            return $this->call('selectMany', [
                'selector' => $selector,
                'resultSelector' => $resultSelector,
                'predicate' => $predicate,
            ]);
        }
        if ($inner->provider() !== $this->queryProvider) {
            throw new \InvalidArgumentException('selectMany() sources must use the same provider instance.');
        }
        if ($resultSelector === null) {
            throw new \InvalidArgumentException('Query-source selectMany() requires a result selector.');
        }
        return $this->call('selectMany', [
            'inner' => $inner->expression(),
            'resultSelector' => $resultSelector,
            'predicate' => $predicate,
        ]);
    }

    /** Keys are relative to each source; result fields use outer.* and inner.*. */
    public function join(
        IQueryable $inner,
        ValueExpression|\Closure $outerKey,
        ValueExpression|\Closure $innerKey,
        ValueExpression $resultSelector,
    ): self
    {
        $outerKey = $outerKey instanceof \Closure ? ClosureTranslator::translate($outerKey, $this->queryProvider) : $outerKey;
        $innerKey = $innerKey instanceof \Closure ? ClosureTranslator::translate($innerKey, $this->queryProvider) : $innerKey;
        return $this->joinQuery('join', $inner, $outerKey, $innerKey, $resultSelector);
    }

    public function leftJoin(
        IQueryable $inner,
        ValueExpression|\Closure $outerKey,
        ValueExpression|\Closure $innerKey,
        ValueExpression $resultSelector,
    ): self
    {
        $outerKey = $outerKey instanceof \Closure ? ClosureTranslator::translate($outerKey, $this->queryProvider) : $outerKey;
        $innerKey = $innerKey instanceof \Closure ? ClosureTranslator::translate($innerKey, $this->queryProvider) : $innerKey;
        return $this->joinQuery('leftJoin', $inner, $outerKey, $innerKey, $resultSelector);
    }

    public function rightJoin(
        IQueryable $inner,
        ValueExpression|\Closure $outerKey,
        ValueExpression|\Closure $innerKey,
        ValueExpression $resultSelector,
    ): self {
        $outerKey = $outerKey instanceof \Closure ? ClosureTranslator::translate($outerKey, $this->queryProvider) : $outerKey;
        $innerKey = $innerKey instanceof \Closure ? ClosureTranslator::translate($innerKey, $this->queryProvider) : $innerKey;
        return $this->joinQuery('rightJoin', $inner, $outerKey, $innerKey, $resultSelector);
    }

    /** Result fields use outer.* and inner (an IEnumerable of matching rows). */
    public function groupJoin(
        IQueryable $inner,
        ValueExpression|\Closure $outerKey,
        ValueExpression|\Closure $innerKey,
        ValueExpression $resultSelector,
    ): self {
        $outerKey = $outerKey instanceof \Closure ? ClosureTranslator::translate($outerKey, $this->queryProvider) : $outerKey;
        $innerKey = $innerKey instanceof \Closure ? ClosureTranslator::translate($innerKey, $this->queryProvider) : $innerKey;
        return $this->joinQuery('groupJoin', $inner, $outerKey, $innerKey, $resultSelector);
    }

    /** Pair rows by enumeration position; result fields use outer.* and inner.*. */
    public function zip(IQueryable $second, ValueExpression $resultSelector): self
    {
        if ($second->provider() !== $this->queryProvider) {
            throw new \InvalidArgumentException('Zipped queries must use the same provider instance.');
        }
        return $this->call('zip', [
            'second' => $second->expression(),
            'resultSelector' => $resultSelector,
        ]);
    }

    /** @return IQueryable<list<T>> */
    public function chunk(int $size): self
    {
        if ($size <= 0) {
            throw new \InvalidArgumentException('Chunk size must be greater than zero.');
        }
        return $this->call('chunk', ['size' => $size]);
    }

    public function order(): self
    {
        return $this->call('order', ['descending' => false]);
    }

    public function orderDescending(): self
    {
        return $this->call('order', ['descending' => true]);
    }

    public function elementAt(int $index): mixed
    {
        if ($index < 0) {
            throw new \OutOfBoundsException('The index is outside the sequence.');
        }
        return $this->queryProvider->execute(new MethodCallExpression(
            $this->queryExpression, 'elementAt', ['index' => $index],
        ));
    }

    public function elementAtOrDefault(int $index, mixed $default = null): mixed
    {
        if ($index < 0) {
            return $default;
        }
        return $this->queryProvider->execute(new MethodCallExpression(
            $this->queryExpression, 'elementAtOrDefault', ['index' => $index, 'default' => $default],
        ));
    }

    public function orderBy(ValueExpression|\Closure $keySelector): self
    {
        $keySelector = $keySelector instanceof \Closure ? ClosureTranslator::translate($keySelector, $this->queryProvider) : $keySelector;
        return $this->call('orderBy', ['keySelector' => $keySelector, 'descending' => false]);
    }

    public function orderByDescending(ValueExpression|\Closure $keySelector): self
    {
        $keySelector = $keySelector instanceof \Closure ? ClosureTranslator::translate($keySelector, $this->queryProvider) : $keySelector;
        return $this->call('orderBy', ['keySelector' => $keySelector, 'descending' => true]);
    }

    public function thenBy(ValueExpression|\Closure $keySelector): self
    {
        $keySelector = $keySelector instanceof \Closure ? ClosureTranslator::translate($keySelector, $this->queryProvider) : $keySelector;
        return $this->secondaryOrder($keySelector, false);
    }
    public function thenByDescending(ValueExpression|\Closure $keySelector): self
    {
        $keySelector = $keySelector instanceof \Closure ? ClosureTranslator::translate($keySelector, $this->queryProvider) : $keySelector;
        return $this->secondaryOrder($keySelector, true);
    }
    public function firstOrDefault(ValueExpression|\Closure|null $predicate = null, mixed $default = null): mixed
    {
        $predicate = $predicate instanceof \Closure ? ClosureTranslator::translate($predicate, $this->queryProvider) : $predicate;
        return $this->queryProvider->execute(new MethodCallExpression($this->queryExpression,
            'firstOrDefault', ['predicate' => $predicate, 'default' => $default]));
    }
    public function single(ValueExpression|\Closure|null $predicate = null): mixed
    {
        $predicate = $predicate instanceof \Closure ? ClosureTranslator::translate($predicate, $this->queryProvider) : $predicate;
        return $this->queryProvider->execute(new MethodCallExpression($this->queryExpression,
            'single', ['predicate' => $predicate]));
    }
    public function singleOrDefault(ValueExpression|\Closure|null $predicate = null, mixed $default = null): mixed
    {
        $predicate = $predicate instanceof \Closure ? ClosureTranslator::translate($predicate, $this->queryProvider) : $predicate;
        return $this->queryProvider->execute(new MethodCallExpression($this->queryExpression,
            'singleOrDefault', ['predicate' => $predicate, 'default' => $default]));
    }

    public function last(ValueExpression|\Closure|null $predicate = null): mixed
    {
        $predicate = $predicate instanceof \Closure ? ClosureTranslator::translate($predicate, $this->queryProvider) : $predicate;
        return $this->queryProvider->execute(new MethodCallExpression($this->queryExpression,
            'last', ['predicate' => $predicate]));
    }

    public function lastOrDefault(ValueExpression|\Closure|null $predicate = null, mixed $default = null): mixed
    {
        $predicate = $predicate instanceof \Closure ? ClosureTranslator::translate($predicate, $this->queryProvider) : $predicate;
        return $this->queryProvider->execute(new MethodCallExpression($this->queryExpression,
            'lastOrDefault', ['predicate' => $predicate, 'default' => $default]));
    }

    public function all(ValueExpression|\Closure $predicate): bool
    {
        $predicate = $predicate instanceof \Closure ? ClosureTranslator::translate($predicate, $this->queryProvider) : $predicate;
        return $this->queryProvider->execute(new MethodCallExpression($this->queryExpression,
            'all', ['predicate' => $predicate]));
    }
    public function contains(mixed $value): bool
    {
        return $this->queryProvider->execute(new MethodCallExpression($this->queryExpression,
            'contains', ['value' => $value]));
    }
    public function skip(int $count): self
    {
        return $this->call('skip', ['count' => max(0, $count)]);
    }

    public function take(int $count): self
    {
        return $this->call('take', ['count' => max(0, $count)]);
    }

    public function append(mixed $value): self
    {
        return $this->call('append', ['value' => $value]);
    }

    public function prepend(mixed $value): self
    {
        return $this->call('prepend', ['value' => $value]);
    }

    public function reverse(): self
    {
        return $this->call('reverse', []);
    }

    public function defaultIfEmpty(mixed $default = null): self
    {
        return $this->call('defaultIfEmpty', ['default' => $default]);
    }

    public function skipWhile(ValueExpression|\Closure $predicate): self
    {
        $predicate = $predicate instanceof \Closure ? ClosureTranslator::translate($predicate, $this->queryProvider, true) : $predicate;
        return $this->call('skipWhile', ['predicate' => $predicate]);
    }

    public function takeWhile(ValueExpression|\Closure $predicate): self
    {
        $predicate = $predicate instanceof \Closure ? ClosureTranslator::translate($predicate, $this->queryProvider, true) : $predicate;
        return $this->call('takeWhile', ['predicate' => $predicate]);
    }

    public function skipLast(int $count): self
    {
        return $this->nonNegativeCall('skipLast', $count);
    }

    public function takeLast(int $count): self
    {
        return $this->nonNegativeCall('takeLast', $count);
    }

    public function sum(ValueExpression|\Closure $selector): int|float|string
    {
        $selector = $selector instanceof \Closure ? ClosureTranslator::translate($selector, $this->queryProvider) : $selector;
        return $this->queryProvider->execute(new MethodCallExpression(
            $this->queryExpression, 'sum', ['selector' => $selector],
        ));
    }

    public function average(ValueExpression|\Closure $selector): int|float|string|null
    {
        $selector = $selector instanceof \Closure ? ClosureTranslator::translate($selector, $this->queryProvider) : $selector;
        return $this->queryProvider->execute(new MethodCallExpression(
            $this->queryExpression, 'average', ['selector' => $selector],
        ));
    }

    public function min(ValueExpression|\Closure $selector): mixed
    {
        $selector = $selector instanceof \Closure ? ClosureTranslator::translate($selector, $this->queryProvider) : $selector;
        return $this->queryProvider->execute(new MethodCallExpression(
            $this->queryExpression, 'min', ['selector' => $selector],
        ));
    }

    public function max(ValueExpression|\Closure $selector): mixed
    {
        $selector = $selector instanceof \Closure ? ClosureTranslator::translate($selector, $this->queryProvider) : $selector;
        return $this->queryProvider->execute(new MethodCallExpression(
            $this->queryExpression, 'max', ['selector' => $selector],
        ));
    }

    public function longCount(ValueExpression|\Closure|null $predicate = null): int
    {
        $query = $predicate === null ? $this : $this->where($predicate);
        return $query->count();
    }

    public function count(): int
    {
        return $this->queryProvider->execute($this->terminal('count'));
    }

    public function any(ValueExpression|\Closure|null $predicate = null): bool
    {
        $query = $predicate === null ? $this : $this->where($predicate);
        return $this->queryProvider->execute($query->terminal('any'));
    }

    public function first(ValueExpression|\Closure|null $predicate = null): mixed
    {
        $predicate = $predicate instanceof \Closure ? ClosureTranslator::translate($predicate, $this->queryProvider) : $predicate;
        $query = $predicate === null ? $this : $this->where($predicate);
        return $this->queryProvider->execute($query->terminal('first'));
    }

    public function asEnumerable(): IEnumerable
    {
        return Enumerable::from($this);
    }

    public function toArray(): array
    {
        return iterator_to_array($this->getIterator(), false);
    }

    public function getIterator(): Traversable
    {
        $result = $this->queryProvider->execute($this->queryExpression);
        if (!is_iterable($result)) {
            throw new \LogicException('The provider did not return an iterable query result.');
        }
        yield from $result;
    }

    private function setQuery(string $method, IQueryable $second): self
    {
        if ($second->provider() !== $this->queryProvider) {
            throw new \InvalidArgumentException('Set queries must use the same provider instance.');
        }
        return $this->call($method, ['second' => $second->expression()]);
    }

    private function secondaryOrder(ValueExpression $keySelector, bool $descending): self
    {
        if (!$this->queryExpression instanceof MethodCallExpression
            || !in_array($this->queryExpression->method, ['order', 'orderBy', 'thenBy'], true)) {
            throw new \LogicException('thenBy() must follow order(), orderBy() or thenBy().');
        }
        return $this->call('thenBy', ['keySelector' => $keySelector, 'descending' => $descending]);
    }

    private function joinQuery(
        string $method,
        IQueryable $inner,
        ValueExpression $outerKey,
        ValueExpression $innerKey,
        ValueExpression $resultSelector,
    ): self
    {
        if ($inner->provider() !== $this->queryProvider) {
            throw new \InvalidArgumentException('Joined queries must use the same provider instance.');
        }
        return $this->call($method, [
            'inner' => $inner->expression(),
            'outerKey' => $outerKey,
            'innerKey' => $innerKey,
            'resultSelector' => $resultSelector,
        ]);
    }

    private function call(string $method, array $arguments): self
    {
        return new self(
            $this->queryProvider,
            new MethodCallExpression($this->queryExpression, $method, $arguments),
        );
    }

    private function nonNegativeCall(string $method, int $count): self
    {
        if ($count < 0) {
            throw new \InvalidArgumentException("{$method} count cannot be negative.");
        }
        return $this->call($method, ['count' => $count]);
    }

    private function terminal(string $method): MethodCallExpression
    {
        return new MethodCallExpression($this->queryExpression, $method);
    }
}
