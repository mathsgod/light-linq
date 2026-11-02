<?php

declare(strict_types=1);

namespace Light\Linq\Collections\Generic;

use OutOfRangeException;
use Traversable;

/**
 * A mutable, zero-based list modelled after System.Collections.Generic.List<T>.
 * PHP reserves the name "list", so the PHP class is named GenericList.
 *
 * @template T
 * @implements IList<T>
 */
final class GenericList implements IList
{
    /** @var list<T> */
    private array $items;
    private int $version = 0;

    /** @param iterable<T> $items */
    public function __construct(iterable $items = [])
    {
        $this->items = is_array($items)
            ? array_values($items)
            : array_values(iterator_to_array($items, false));
    }

    /** @param T $item */
    public function add(mixed $item): void
    {
        $this->items[] = $item;
        ++$this->version;
    }

    /** Removes the first strictly equal item. @param T $item */
    public function remove(mixed $item): bool
    {
        $index = $this->indexOf($item);
        if ($index < 0) {
            return false;
        }
        $this->removeAt($index);
        return true;
    }

    public function removeAt(int $index): void
    {
        $this->assertIndex($index);
        array_splice($this->items, $index, 1);
        ++$this->version;
    }

    public function removeRange(int $index, int $count): void
    {
        $this->assertRange($index, $count);
        if ($count === 0) {
            return;
        }
        array_splice($this->items, $index, $count);
        ++$this->version;
    }

    /** @param T $item */
    public function insert(int $index, mixed $item): void
    {
        if ($index < 0 || $index > count($this->items)) {
            throw new OutOfRangeException("List index out of range: {$index}");
        }
        array_splice($this->items, $index, 0, [$item]);
        ++$this->version;
    }

    public function insertRange(int $index, iterable $items): void
    {
        if ($index < 0 || $index > count($this->items)) {
            throw new OutOfRangeException("List index out of range: {$index}");
        }
        $inserted = is_array($items)
            ? array_values($items)
            : array_values(iterator_to_array($items, false));
        if ($inserted === []) {
            return;
        }
        array_splice($this->items, $index, 0, $inserted);
        ++$this->version;
    }

    /** @return self<T> */
    public function getRange(int $index, int $count): self
    {
        $this->assertRange($index, $count);
        return new self(array_slice($this->items, $index, $count));
    }

    public function clear(): void
    {
        if ($this->items === []) {
            return;
        }
        $this->items = [];
        ++$this->version;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** @return T */
    public function get(int $index): mixed
    {
        $this->assertIndex($index);
        return $this->items[$index];
    }

    /** @param T $item */
    public function set(int $index, mixed $item): void
    {
        $this->assertIndex($index);
        $this->items[$index] = $item;
        ++$this->version;
    }

    /** @param T $item */
    public function contains(mixed $item): bool
    {
        return $this->indexOf($item) >= 0;
    }

    /** @param T $item */
    public function indexOf(mixed $item): int
    {
        foreach ($this->items as $index => $existing) {
            if ($existing === $item) {
                return $index;
            }
        }
        return -1;
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return list<T> */
    public function toArray(): array
    {
        return $this->items;
    }

    /** @return Traversable<int, T> */
    public function getIterator(): Traversable
    {
        $expectedVersion = $this->version;
        foreach ($this->items as $item) {
            if ($expectedVersion !== $this->version) {
                throw new \RuntimeException('The list was modified during iteration.');
            }
            yield $item;
        }
        if ($expectedVersion !== $this->version) {
            throw new \RuntimeException('The list was modified during iteration.');
        }
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_int($offset) && $offset >= 0 && $offset < count($this->items);
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (!is_int($offset)) {
            throw new \TypeError('List offsets must be integers.');
        }
        return $this->get($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->add($value);
            return;
        }
        if (!is_int($offset)) {
            throw new \TypeError('List offsets must be integers.');
        }
        $this->set($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        if (!is_int($offset)) {
            throw new \TypeError('List offsets must be integers.');
        }
        $this->removeAt($offset);
    }

    private function assertIndex(int $index): void
    {
        if ($index < 0 || $index >= count($this->items)) {
            throw new OutOfRangeException("List index out of range: {$index}");
        }
    }

    private function assertRange(int $index, int $count): void
    {
        if ($index < 0 || $count < 0 || $index > count($this->items) - $count) {
            throw new OutOfRangeException("Invalid list range: index {$index}, count {$count}");
        }
    }
}
