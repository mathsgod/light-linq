<?php

declare(strict_types=1);

namespace Light\Linq\Collections\Generic;

/** @template T */
interface EqualityComparer
{
    /** @param T $left @param T $right */
    public function equals(mixed $left, mixed $right): bool;

    /** @param T $value */
    public function hash(mixed $value): string;
}
