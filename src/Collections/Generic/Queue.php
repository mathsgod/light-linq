<?php

declare(strict_types=1);

namespace Light\Linq\Collections\Generic;

use Traversable;
use UnderflowException;

/** @template T @implements IReadOnlyCollection<T> */
final class Queue implements IReadOnlyCollection
{
    /** @var array<int, T> */
    private array $items = [];
    private int $head = 0;
    private int $version = 0;

    /** @param iterable<T> $items */
    public function __construct(iterable $items = [])
    {
        foreach ($items as $item) {
            $this->items[] = $item;
        }
    }

    /** @param T $item */
    public function enqueue(mixed $item): void
    {
        $this->items[] = $item;
        ++$this->version;
    }

    /** @return T */
    public function dequeue(): mixed
    {
        if ($this->count() === 0) {
            throw new UnderflowException('The queue is empty.');
        }
        $value = $this->items[$this->head];
        unset($this->items[$this->head++]);
        ++$this->version;
        $this->compactIfNeeded();
        return $value;
    }

    /** @return T */
    public function peek(): mixed
    {
        if ($this->count() === 0) {
            throw new UnderflowException('The queue is empty.');
        }
        return $this->items[$this->head];
    }

    /** @param-out T|null $value */
    public function tryDequeue(mixed &$value): bool
    {
        if ($this->count() === 0) {
            $value = null;
            return false;
        }
        $value = $this->dequeue();
        return true;
    }

    public function clear(): void
    {
        if ($this->count() === 0) {
            return;
        }
        $this->items = [];
        $this->head = 0;
        ++$this->version;
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return list<T> */
    public function toArray(): array
    {
        return array_values($this->items);
    }

    /** @return Traversable<int, T> */
    public function getIterator(): Traversable
    {
        $expectedVersion = $this->version;
        foreach ($this->items as $item) {
            if ($expectedVersion !== $this->version) {
                throw new \RuntimeException('The queue was modified during iteration.');
            }
            yield $item;
        }
        if ($expectedVersion !== $this->version) {
            throw new \RuntimeException('The queue was modified during iteration.');
        }
    }

    private function compactIfNeeded(): void
    {
        if ($this->items === []) {
            $this->head = 0;
            return;
        }
        if ($this->head >= 64 && $this->head > count($this->items)) {
            $this->items = array_values($this->items);
            $this->head = 0;
        }
    }
}
