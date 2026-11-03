<?php
declare(strict_types=1);
namespace Light\Linq;
/**
 * A group produced by Enumerable, Lookup or an in-memory query.
 * @template TKey
 * @template TElement
 * @implements \IteratorAggregate<int, TElement>
 */
final readonly class Grouping implements \IteratorAggregate, \Countable
{
    /** @param TKey $key @param list<TElement> $items */
    public function __construct(public mixed $key, public array $items) {}
    public function getIterator(): \Traversable { yield from $this->items; }
    public function count(): int { return count($this->items); }
}
