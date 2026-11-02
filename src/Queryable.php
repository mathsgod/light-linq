<?php

declare(strict_types=1);

namespace Light\Linq;

use Light\Linq\Expression\Expression;
use Light\Linq\Expression\MethodCallExpression;
use Light\Linq\Expression\SourceExpression;
use Light\Linq\Expression\ValueExpression;
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

    public function expression(): Expression
    {
        return $this->queryExpression;
    }

    public function provider(): IQueryProvider
    {
        return $this->queryProvider;
    }

    public function groupBy(ValueExpression $keySelector): self
    {
        return $this->call('groupBy', ['keySelector' => $keySelector]);
    }
    public function distinct(): self
    {
        return $this->call('distinct', []);
    }
    public function where(ValueExpression $predicate): self
    {
        return $this->call('where', ['predicate' => $predicate]);
    }

    public function select(ValueExpression $selector): self
    {
        return $this->call('select', ['selector' => $selector]);
    }

    /** Keys are relative to each source; result fields use outer.* and inner.*. */
    public function join(
        IQueryable $inner,
        ValueExpression $outerKey,
        ValueExpression $innerKey,
        ValueExpression $resultSelector,
    ): self
    {
        return $this->joinQuery('join', $inner, $outerKey, $innerKey, $resultSelector);
    }

    public function leftJoin(
        IQueryable $inner,
        ValueExpression $outerKey,
        ValueExpression $innerKey,
        ValueExpression $resultSelector,
    ): self
    {
        return $this->joinQuery('leftJoin', $inner, $outerKey, $innerKey, $resultSelector);
    }

    public function orderBy(ValueExpression $keySelector): self
    {
        return $this->call('orderBy', ['keySelector' => $keySelector, 'descending' => false]);
    }

    public function orderByDescending(ValueExpression $keySelector): self
    {
        return $this->call('orderBy', ['keySelector' => $keySelector, 'descending' => true]);
    }

    public function skip(int $count): self
    {
        return $this->nonNegativeCall('skip', $count);
    }

    public function take(int $count): self
    {
        return $this->nonNegativeCall('take', $count);
    }

    public function count(): int
    {
        return $this->queryProvider->execute($this->terminal('count'));
    }

    public function any(?ValueExpression $predicate = null): bool
    {
        $query = $predicate === null ? $this : $this->where($predicate);
        return $this->queryProvider->execute($query->terminal('any'));
    }

    public function first(?ValueExpression $predicate = null): mixed
    {
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
