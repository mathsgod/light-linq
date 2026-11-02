<?php

declare(strict_types=1);

namespace Light\Linq;

/** @template T @extends Enumerable<T> @implements IOrderedEnumerable<T> */
final class OrderedEnumerable extends Enumerable implements IOrderedEnumerable
{
    /**
     * @param IEnumerable<T> $source
     * @param list<array{0: callable(T): mixed, 1: bool}> $criteria
     */
    public function __construct(
        private readonly IEnumerable $source,
        private readonly array $criteria,
    ) {
        parent::__construct(fn (): iterable => $this->sorted());
    }

    public function thenBy(callable $keySelector): IOrderedEnumerable
    {
        return new self($this->source, [...$this->criteria, [$keySelector, false]]);
    }

    public function thenByDescending(callable $keySelector): IOrderedEnumerable
    {
        return new self($this->source, [...$this->criteria, [$keySelector, true]]);
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
            foreach ($this->criteria as [$selector, $descending]) {
                $comparison = $selector($left['value']) <=> $selector($right['value']);
                if ($comparison !== 0) {
                    return $descending ? -$comparison : $comparison;
                }
            }
            return $left['index'] <=> $right['index'];
        });

        return array_column($decorated, 'value');
    }
}
