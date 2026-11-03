<?php

declare(strict_types=1);

namespace Light\Linq;

/** @template T @extends Enumerable<T> @implements IOrderedEnumerable<T> */
final class OrderedEnumerable extends Enumerable implements IOrderedEnumerable
{
    /**
     * @param IEnumerable<T> $source
     * @param list<array{0: callable(T): mixed, 1: bool, 2?: (callable(mixed, mixed): int)|null}> $criteria
     */
    public function __construct(
        private readonly IEnumerable $source,
        private readonly array $criteria,
    ) {
        parent::__construct(fn (): iterable => $this->sorted());
    }

    public function thenBy(callable $keySelector, ?callable $comparer = null): IOrderedEnumerable
    {
        return new self($this->source, [...$this->criteria, [$keySelector, false, $comparer]]);
    }

    public function thenByDescending(callable $keySelector, ?callable $comparer = null): IOrderedEnumerable
    {
        return new self($this->source, [...$this->criteria, [$keySelector, true, $comparer]]);
    }

    /** @return list<T> */
    private function sorted(): array
    {
        $decorated = [];
        $position = 0;
        foreach ($this->source as $item) {
            $decorated[] = ['index' => $position++, 'value' => $item];
        }

        usort($decorated, function (array $left, array $right): int {
            foreach ($this->criteria as $criterion) {
                [$selector, $descending] = $criterion;
                $comparer = $criterion[2] ?? null;
                $leftKey = $selector($left['value']);
                $rightKey = $selector($right['value']);
                // Normalize the sign before reversing so PHP_INT_MIN cannot overflow.
                $comparison = $comparer === null
                    ? $leftKey <=> $rightKey
                    : $comparer($leftKey, $rightKey) <=> 0;
                if ($comparison !== 0) {
                    return $descending ? -$comparison : $comparison;
                }
            }
            return $left['index'] <=> $right['index'];
        });

        return array_column($decorated, 'value');
    }
}
