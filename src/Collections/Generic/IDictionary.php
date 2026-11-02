<?php

declare(strict_types=1);

namespace Light\Linq\Collections\Generic;

/**
 * @template TKey
 * @template TValue
 * @extends IReadOnlyCollection<KeyValuePair<TKey, TValue>>
 */
interface IDictionary extends IReadOnlyCollection
{
    /** @param TKey $key @param TValue $value */
    public function add(mixed $key, mixed $value): void;

    /** @param TKey $key @param TValue $value */
    public function set(mixed $key, mixed $value): void;

    /** @param TKey $key @return TValue */
    public function get(mixed $key): mixed;

    /** @param TKey $key @param TValue $default @return TValue */
    public function getOrDefault(mixed $key, mixed $default): mixed;

    /** @param TKey $key @param TValue $value */
    public function tryAdd(mixed $key, mixed $value): bool;

    /** @param TKey $key @param-out TValue|null $value */
    public function tryGetValue(mixed $key, mixed &$value): bool;

    /** @param TKey $key */
    public function containsKey(mixed $key): bool;

    /** @param TValue $value */
    public function containsValue(mixed $value): bool;

    /** @param TKey $key */
    public function remove(mixed $key): bool;

    public function clear(): void;

    public function isEmpty(): bool;

    /** @return list<TKey> */
    public function keys(): array;

    /** @return list<TValue> */
    public function values(): array;
}
