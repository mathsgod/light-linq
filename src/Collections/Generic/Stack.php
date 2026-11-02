<?php

declare(strict_types=1);

namespace Light\Linq\Collections\Generic;

use Traversable;
use UnderflowException;

/** @template T @implements IReadOnlyCollection<T> */
final class Stack implements IReadOnlyCollection
{
    /** @var list<T> */
    private array $items;
    private int $version = 0;

    /** @param iterable<T> $items Items are pushed in iteration order. */
    public function __construct(iterable $items = [])
    {
        $this->items = is_array($items)
            ? array_values($items)
            : array_values(iterator_to_array($items, false));
    }

    /** @param T $item */
    public function push(mixed $item): void
    {
        $this->items[] = $item;
        ++$this->version;
    }

    /** @return T */
    public function pop(): mixed
    {
        if ($this->items === []) {
            throw new UnderflowException('The stack is empty.');
        }
        ++$this->version;
        return array_pop($this->items);
    }

    /** @return T */
    public function peek(): mixed
    {
        if ($this->items === []) {
            throw new UnderflowException('The stack is empty.');
        }
        return $this->items[array_key_last($this->items)];
    }

    /** @param-out T|null $value */
    public function tryPop(mixed &$value): bool
    {
        if ($this->items === []) {
            $value = null;
            return false;
        }
        $value = $this->pop();
        return true;
    }

    public function clear(): void
    {
        if ($this->items === []) {
            return;
        }
        $this->items = [];
        ++$this->version;
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** Top item first, matching .NET Stack<T>.ToArray(). @return list<T> */
    public function toArray(): array
    {
        return array_reverse($this->items);
    }

    /** @return Traversable<int, T> */
    public function getIterator(): Traversable
    {
        $expectedVersion = $this->version;
        foreach ($this->toArray() as $item) {
            if ($expectedVersion !== $this->version) {
                throw new \RuntimeException('The stack was modified during iteration.');
            }
            yield $item;
        }
        if ($expectedVersion !== $this->version) {
            throw new \RuntimeException('The stack was modified during iteration.');
        }
    }
}
