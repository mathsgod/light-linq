<?php
declare(strict_types=1);
namespace Light\Linq;
/** A group produced by Enumerable or an in-memory query. */
final readonly class Grouping implements \IteratorAggregate, \Countable
{
    public function __construct(public mixed $key, public array $items) {}
    public function getIterator(): \Traversable { yield from $this->items; }
    public function count(): int { return count($this->items); }
}
