<?php

declare(strict_types=1);

namespace Light\Linq\Collections\Generic;

/** @template T @implements EqualityComparer<T> */
final class DefaultEqualityComparer implements EqualityComparer
{
    public function equals(mixed $left, mixed $right): bool
    {
        if (is_float($left) && is_nan($left) && is_float($right) && is_nan($right)) {
            return true;
        }

        return $left === $right;
    }

    public function hash(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_object($value) => 'object:'.spl_object_id($value),
            is_resource($value) => 'resource:'.get_resource_id($value),
            is_float($value) && is_nan($value) => 'float:nan',
            is_float($value) => 'float:'.serialize($value),
            is_int($value) => 'int:'.$value,
            is_string($value) => 'string:'.$value,
            is_bool($value) => 'bool:'.($value ? '1' : '0'),
            is_array($value) => 'array:'.hash('xxh128', serialize($value)),
            default => throw new \InvalidArgumentException('Unsupported value type.'),
        };
    }
}
