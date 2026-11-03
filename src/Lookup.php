<?php

declare(strict_types=1);

namespace Light\Linq;

use Countable;
use IteratorAggregate;
use Light\Collections\Generic\DefaultEqualityComparer;
use Light\Collections\Generic\EqualityComparer;
use Traversable;

/**
 * An eagerly materialized, read-only index of groups in first-key order.
 *
 * @template TKey
 * @template TElement
 * @implements IteratorAggregate<int, Grouping<TKey, TElement>>
 */
final readonly class Lookup implements IteratorAggregate, Countable
{
    /** @var list<Grouping<TKey, TElement>> */
    private array $groups;

    /** @var array<string, list<int>> */
    private array $buckets;

    /** @var EqualityComparer<TKey> */
    private EqualityComparer $comparer;

    /**
     * @template TSource
     * @param iterable<TSource> $source
     * @param callable(TSource): TKey $keySelector
     * @param (callable(TSource): TElement)|null $elementSelector Defaults to the source element.
     * @param EqualityComparer<TKey>|null $comparer
     */
    public function __construct(
        iterable $source,
        callable $keySelector,
        ?callable $elementSelector = null,
        ?EqualityComparer $comparer = null,
    ) {
        $this->comparer = $comparer ?? new DefaultEqualityComparer();
        $groups = $buckets = [];
        foreach ($source as $item) {
            $key = $keySelector($item);
            $element = $elementSelector === null ? $item : $elementSelector($item);
            $hash = $this->comparer->hash($key);
            foreach ($buckets[$hash] ?? [] as $index) {
                if ($this->comparer->equals($groups[$index]['key'], $key)) {
                    $groups[$index]['items'][] = $element;
                    continue 2;
                }
            }
            $buckets[$hash][] = count($groups);
            $groups[] = ['key' => $key, 'items' => [$element]];
        }
        $this->groups = array_map(static fn (array $group): Grouping => new Grouping($group['key'], $group['items']), $groups);
        $this->buckets = $buckets;
    }

    /** @param TKey $key @return IEnumerable<TElement> */
    public function get(mixed $key): IEnumerable
    {
        $index = $this->indexOf($key);
        return Enumerable::from($index === null ? [] : $this->groups[$index]->items);
    }

    /** @param TKey $key */
    public function contains(mixed $key): bool
    {
        return $this->indexOf($key) !== null;
    }

    /** Number of groups, rather than total elements. */
    public function count(): int
    {
        return count($this->groups);
    }

    /** @return Traversable<int, Grouping<TKey, TElement>> */
    public function getIterator(): Traversable
    {
        yield from $this->groups;
    }

    /** @param TKey $key */
    private function indexOf(mixed $key): ?int
    {
        foreach ($this->buckets[$this->comparer->hash($key)] ?? [] as $index) {
            if ($this->comparer->equals($this->groups[$index]->key, $key)) {
                return $index;
            }
        }
        return null;
    }
}
