<?php

declare(strict_types=1);

namespace Light\Linq\Collections\Generic;

use Traversable;

/** @template T @implements ISet<T> */
final class HashSet implements ISet
{
    /** @var array<string, list<T>> */
    private array $buckets = [];
    private int $size = 0;
    private int $version = 0;

    /** @var EqualityComparer<T> */
    private readonly EqualityComparer $comparer;

    /** @param iterable<T> $items @param EqualityComparer<T>|null $comparer */
    public function __construct(iterable $items = [], ?EqualityComparer $comparer = null)
    {
        $this->comparer = $comparer ?? new DefaultEqualityComparer();
        foreach ($items as $item) {
            $this->tryAdd($item);
        }
    }

    public function add(mixed $item): void
    {
        $this->tryAdd($item);
    }

    public function tryAdd(mixed $item): bool
    {
        $hash = $this->comparer->hash($item);
        if ($this->bucketIndex($hash, $item) !== null) {
            return false;
        }
        $this->buckets[$hash][] = $item;
        ++$this->size;
        ++$this->version;
        return true;
    }

    public function remove(mixed $item): bool
    {
        $hash = $this->comparer->hash($item);
        $index = $this->bucketIndex($hash, $item);
        if ($index === null) {
            return false;
        }
        array_splice($this->buckets[$hash], $index, 1);
        if ($this->buckets[$hash] === []) {
            unset($this->buckets[$hash]);
        }
        --$this->size;
        ++$this->version;
        return true;
    }

    public function contains(mixed $item): bool
    {
        $hash = $this->comparer->hash($item);
        return $this->bucketIndex($hash, $item) !== null;
    }

    public function clear(): void
    {
        if ($this->size === 0) {
            return;
        }
        $this->buckets = [];
        $this->size = 0;
        ++$this->version;
    }

    public function isEmpty(): bool
    {
        return $this->size === 0;
    }

    public function count(): int
    {
        return $this->size;
    }

    public function unionWith(iterable $other): void
    {
        foreach ($other as $item) {
            $this->tryAdd($item);
        }
    }

    public function intersectWith(iterable $other): void
    {
        $otherSet = $this->copyOf($other);
        foreach ($this->toArray() as $item) {
            if (!$otherSet->contains($item)) {
                $this->remove($item);
            }
        }
    }

    public function exceptWith(iterable $other): void
    {
        if ($other === $this) {
            $this->clear();
            return;
        }
        foreach ($other as $item) {
            $this->remove($item);
        }
    }

    public function symmetricExceptWith(iterable $other): void
    {
        $otherSet = $this->copyOf($other);
        foreach ($otherSet as $item) {
            if (!$this->remove($item)) {
                $this->add($item);
            }
        }
    }

    public function overlaps(iterable $other): bool
    {
        foreach ($other as $item) {
            if ($this->contains($item)) {
                return true;
            }
        }
        return false;
    }

    public function setEquals(iterable $other): bool
    {
        $otherSet = $this->copyOf($other);
        return $this->size === $otherSet->size && $this->isSubsetOf($otherSet);
    }

    public function isSubsetOf(iterable $other): bool
    {
        $otherSet = $this->copyOf($other);
        foreach ($this as $item) {
            if (!$otherSet->contains($item)) {
                return false;
            }
        }
        return true;
    }

    public function isSupersetOf(iterable $other): bool
    {
        foreach ($other as $item) {
            if (!$this->contains($item)) {
                return false;
            }
        }
        return true;
    }

    /** @return list<T> */
    public function toArray(): array
    {
        $items = [];
        foreach ($this->buckets as $bucket) {
            array_push($items, ...$bucket);
        }
        return $items;
    }

    /** @return Traversable<int, T> */
    public function getIterator(): Traversable
    {
        $expectedVersion = $this->version;
        foreach ($this->buckets as $bucket) {
            foreach ($bucket as $item) {
                if ($expectedVersion !== $this->version) {
                    throw new \RuntimeException('The set was modified during iteration.');
                }
                yield $item;
            }
        }
        if ($expectedVersion !== $this->version) {
            throw new \RuntimeException('The set was modified during iteration.');
        }
    }

    private function bucketIndex(string $hash, mixed $item): ?int
    {
        foreach ($this->buckets[$hash] ?? [] as $index => $existing) {
            if ($this->comparer->equals($existing, $item)) {
                return $index;
            }
        }
        return null;
    }

    /** @param iterable<T> $items @return self<T> */
    private function copyOf(iterable $items): self
    {
        return new self($items, $this->comparer);
    }
}
