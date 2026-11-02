<?php

declare(strict_types=1);

namespace Light\Linq\Collections\Generic;

use ArrayAccess;
use Traversable;

/**
 * Hash-table dictionary modelled after System.Collections.Generic.Dictionary.
 *
 * Iteration yields KeyValuePair objects because PHP iterator keys can only be
 * integers or strings, while this dictionary permits any non-null key type.
 *
 * @template TKey
 * @template TValue
 * @implements ArrayAccess<TKey, TValue>
 * @implements IDictionary<TKey, TValue>
 */
final class Dictionary implements ArrayAccess, IDictionary
{
    /** @var array<string, list<KeyValuePair<TKey, TValue>>> */
    private array $buckets = [];

    private int $size = 0;
    private int $version = 0;

    /** @var EqualityComparer<TKey> */
    private readonly EqualityComparer $comparer;

    /** @param EqualityComparer<TKey>|null $comparer */
    public function __construct(?EqualityComparer $comparer = null)
    {
        $this->comparer = $comparer ?? new DefaultEqualityComparer();
    }

    /** @param TKey $key @param TValue $value */
    public function add(mixed $key, mixed $value): void
    {
        $this->assertKey($key);
        $hash = $this->comparer->hash($key);
        if ($this->indexOf($hash, $key) !== null) {
            throw new \InvalidArgumentException('An element with the same key already exists.');
        }

        $this->buckets[$hash][] = new KeyValuePair($key, $value);
        ++$this->size;
        ++$this->version;
    }

    /** @param TKey $key @param TValue $value */
    public function set(mixed $key, mixed $value): void
    {
        $this->assertKey($key);
        $hash = $this->comparer->hash($key);
        $index = $this->indexOf($hash, $key);
        if ($index === null) {
            $this->buckets[$hash][] = new KeyValuePair($key, $value);
            ++$this->size;
        } else {
            $originalKey = $this->buckets[$hash][$index]->key;
            $this->buckets[$hash][$index] = new KeyValuePair($originalKey, $value);
        }
        ++$this->version;
    }

    /** @param TKey $key @param TValue $value */
    public function tryAdd(mixed $key, mixed $value): bool
    {
        if ($this->containsKey($key)) {
            return false;
        }
        $this->add($key, $value);
        return true;
    }

    /** @param TKey $key @return TValue */
    public function get(mixed $key): mixed
    {
        $this->assertKey($key);
        $hash = $this->comparer->hash($key);
        $index = $this->indexOf($hash, $key);
        if ($index === null) {
            throw new KeyNotFoundException('The requested key was not found.');
        }
        return $this->buckets[$hash][$index]->value;
    }

    public function getOrDefault(mixed $key, mixed $default): mixed
    {
        return $this->tryGetValue($key, $value) ? $value : $default;
    }

    /** @param TKey $key */
    public function containsKey(mixed $key): bool
    {
        $this->assertKey($key);
        $hash = $this->comparer->hash($key);
        return $this->indexOf($hash, $key) !== null;
    }

    /** @param TValue $value */
    public function containsValue(mixed $value): bool
    {
        foreach ($this->buckets as $bucket) {
            foreach ($bucket as $pair) {
                if ($pair->value === $value) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param TKey $key @param-out TValue|null $value */
    public function tryGetValue(mixed $key, mixed &$value): bool
    {
        $this->assertKey($key);
        $hash = $this->comparer->hash($key);
        $index = $this->indexOf($hash, $key);
        if ($index === null) {
            $value = null;
            return false;
        }
        $value = $this->buckets[$hash][$index]->value;
        return true;
    }

    /** @param TKey $key */
    public function remove(mixed $key): bool
    {
        $this->assertKey($key);
        $hash = $this->comparer->hash($key);
        $index = $this->indexOf($hash, $key);
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

    /** @return list<TKey> */
    public function keys(): array
    {
        return array_map(static fn (KeyValuePair $pair): mixed => $pair->key, $this->pairs());
    }

    /** @return list<TValue> */
    public function values(): array
    {
        return array_map(static fn (KeyValuePair $pair): mixed => $pair->value, $this->pairs());
    }

    /** @return list<KeyValuePair<TKey, TValue>> */
    public function toArray(): array
    {
        return $this->pairs();
    }

    public function count(): int
    {
        return $this->size;
    }

    /** @return Traversable<int, KeyValuePair<TKey, TValue>> */
    public function getIterator(): Traversable
    {
        $expectedVersion = $this->version;
        foreach ($this->buckets as $bucket) {
            foreach ($bucket as $pair) {
                if ($this->version !== $expectedVersion) {
                    throw new \RuntimeException('The dictionary was modified during iteration.');
                }
                yield $pair;
            }
        }
        if ($this->version !== $expectedVersion) {
            throw new \RuntimeException('The dictionary was modified during iteration.');
        }
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->containsKey($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            throw new \InvalidArgumentException('Dictionary keys cannot be null.');
        }
        $this->set($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->remove($offset);
    }

    /** @param TKey $key */
    private function indexOf(string $hash, mixed $key): ?int
    {
        foreach ($this->buckets[$hash] ?? [] as $index => $pair) {
            if ($this->comparer->equals($pair->key, $key)) {
                return $index;
            }
        }
        return null;
    }

    /** @return list<KeyValuePair<TKey, TValue>> */
    private function pairs(): array
    {
        return array_merge(...array_values($this->buckets ?: [[]]));
    }

    private function assertKey(mixed $key): void
    {
        if ($key === null) {
            throw new \InvalidArgumentException('Dictionary keys cannot be null.');
        }
    }
}
