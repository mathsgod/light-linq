<?php

declare(strict_types=1);

namespace Light\Linq;

use Closure;
use Light\Collections\Generic\DefaultEqualityComparer;
use Light\Collections\Generic\EqualityComparer;
use Light\Collections\Generic\HashSet;
use Light\Collections\Generic\KeyValuePair;
use Traversable;

/** @template T @implements IEnumerable<T> */
class Enumerable implements IEnumerable
{
    /** @var Closure(): iterable<T> */
    private readonly Closure $factory;

    /** @param callable(): iterable<T> $factory */
    protected function __construct(callable $factory)
    {
        $this->factory = Closure::fromCallable($factory);
    }

    /** @template TSource @param iterable<TSource> $source @return IEnumerable<TSource> */
    public static function from(iterable $source): IEnumerable
    {
        if ($source instanceof IEnumerable) {
            return $source;
        }
        return new self(static fn (): iterable => $source);
    }

    /** @template TSource @param callable(): iterable<TSource> $factory @return IEnumerable<TSource> */
    public static function defer(callable $factory): IEnumerable
    {
        return new self($factory);
    }

    /** @return IEnumerable<never> */
    public static function empty(): IEnumerable
    {
        return new self(static fn (): iterable => []);
    }

    /** @return IEnumerable<int> */
    public static function range(int $start, int $count): IEnumerable
    {
        self::assertNonNegative($count);
        if ($count > 0 && $start > PHP_INT_MAX - ($count - 1)) {
            throw new \InvalidArgumentException('The range exceeds the maximum PHP integer.');
        }
        return new self(static function () use ($start, $count): iterable {
            for ($offset = 0; $offset < $count; ++$offset) {
                yield $start + $offset;
            }
        });
    }

    /** @template TElement @param TElement $value @return IEnumerable<TElement> */
    public static function repeat(mixed $value, int $count): IEnumerable
    {
        self::assertNonNegative($count);
        return new self(static function () use ($value, $count): iterable {
            for ($index = 0; $index < $count; ++$index) {
                yield $value;
            }
        });
    }

    public function getIterator(): Traversable
    {
        yield from ($this->factory)();
    }

    public function select(callable $selector): IEnumerable
    {
        $source = $this;
        return new self(static function () use ($source, $selector): iterable {
            foreach ($source as $item) {
                yield $selector($item);
            }
        });
    }

    public function where(callable $predicate): IEnumerable
    {
        $source = $this;
        return new self(static function () use ($source, $predicate): iterable {
            foreach ($source as $item) {
                if ($predicate($item)) {
                    yield $item;
                }
            }
        });
    }

    public function selectMany(callable $selector): IEnumerable
    {
        $source = $this;
        return new self(static function () use ($source, $selector): iterable {
            foreach ($source as $item) {
                yield from $selector($item);
            }
        });
    }

    public function groupBy(callable $keySelector, ?EqualityComparer $comparer = null): IEnumerable
    {
        $source = $this;
        return new self(static function () use ($source, $keySelector, $comparer): iterable {
            [$groups] = self::groupLookup($source, $keySelector, $comparer ?? new DefaultEqualityComparer());
            foreach ($groups as $group) {
                yield new Grouping($group['key'], $group['items']);
            }
        });
    }

    public function groupJoin(
        iterable $inner,
        callable $outerKeySelector,
        callable $innerKeySelector,
        callable $resultSelector,
        ?EqualityComparer $comparer = null,
    ): IEnumerable {
        $source = $this;
        return new self(static function () use (
            $source, $inner, $outerKeySelector, $innerKeySelector, $resultSelector, $comparer,
        ): iterable {
            $comparer ??= new DefaultEqualityComparer();
            $groups = $buckets = null;
            foreach ($source as $outerItem) {
                if ($groups === null) {
                    [$groups, $buckets] = self::groupLookup($inner, $innerKeySelector, $comparer);
                }
                $key = $outerKeySelector($outerItem);
                $matches = [];
                foreach ($buckets[$comparer->hash($key)] ?? [] as $index) {
                    if ($comparer->equals($key, $groups[$index]['key'])) {
                        $matches = $groups[$index]['items'];
                        break;
                    }
                }
                yield $resultSelector($outerItem, self::from($matches));
            }
        });
    }

    /**
     * @return array{list<array{key: mixed, items: list<mixed>}>, array<string, list<int>>}
     */
    private static function groupLookup(iterable $source, callable $keySelector, EqualityComparer $comparer): array
    {
        $groups = [];
        $buckets = [];
        foreach ($source as $item) {
            $key = $keySelector($item);
            $hash = $comparer->hash($key);
            foreach ($buckets[$hash] ?? [] as $index) {
                if ($comparer->equals($groups[$index]['key'], $key)) {
                    $groups[$index]['items'][] = $item;
                    continue 2;
                }
            }
            $buckets[$hash][] = count($groups);
            $groups[] = ['key' => $key, 'items' => [$item]];
        }
        return [$groups, $buckets];
    }

    public function orderBy(callable $keySelector): IOrderedEnumerable
    {
        return new OrderedEnumerable($this, [[$keySelector, false]]);
    }

    public function orderByDescending(callable $keySelector): IOrderedEnumerable
    {
        return new OrderedEnumerable($this, [[$keySelector, true]]);
    }

    public function skip(int $count): IEnumerable
    {
        self::assertNonNegative($count);
        $source = $this;
        return new self(static function () use ($source, $count): iterable {
            $index = 0;
            foreach ($source as $item) {
                if ($index++ >= $count) {
                    yield $item;
                }
            }
        });
    }

    public function take(int $count): IEnumerable
    {
        self::assertNonNegative($count);
        $source = $this;
        return new self(static function () use ($source, $count): iterable {
            if ($count === 0) {
                return;
            }
            $taken = 0;
            foreach ($source as $item) {
                yield $item;
                if (++$taken === $count) {
                    return;
                }
            }
        });
    }

    public function skipWhile(callable $predicate): IEnumerable
    {
        $source = $this;
        return new self(static function () use ($source, $predicate): iterable {
            $yielding = false;
            foreach ($source as $item) {
                $yielding = $yielding || !$predicate($item);
                if ($yielding) {
                    yield $item;
                }
            }
        });
    }

    public function takeWhile(callable $predicate): IEnumerable
    {
        $source = $this;
        return new self(static function () use ($source, $predicate): iterable {
            foreach ($source as $item) {
                if (!$predicate($item)) {
                    return;
                }
                yield $item;
            }
        });
    }

    public function skipLast(int $count): IEnumerable
    {
        self::assertNonNegative($count);
        $source = $this;
        return new self(static function () use ($source, $count): iterable {
            $buffer = new \SplQueue();
            foreach ($source as $item) {
                if ($count === 0) {
                    yield $item;
                    continue;
                }
                $buffer->enqueue($item);
                if ($buffer->count() > $count) {
                    yield $buffer->dequeue();
                }
            }
        });
    }

    public function takeLast(int $count): IEnumerable
    {
        self::assertNonNegative($count);
        $source = $this;
        return new self(static function () use ($source, $count): iterable {
            if ($count === 0) {
                return;
            }
            $buffer = [];
            foreach ($source as $item) {
                $buffer[] = $item;
                if (count($buffer) > $count) {
                    array_shift($buffer);
                }
            }
            yield from $buffer;
        });
    }

    public function distinct(?callable $keySelector = null): IEnumerable
    {
        $source = $this;
        $keySelector ??= static fn (mixed $item): mixed => $item;
        return new self(static function () use ($source, $keySelector): iterable {
            $seen = [];
            foreach ($source as $item) {
                $key = $keySelector($item);
                foreach ($seen as $existing) {
                    if ($existing === $key) {
                        continue 2;
                    }
                }
                $seen[] = $key;
                yield $item;
            }
        });
    }

    public function append(mixed $value): IEnumerable
    {
        $source = $this;
        return new self(static function () use ($source, $value): iterable {
            yield from $source;
            yield $value;
        });
    }

    public function prepend(mixed $value): IEnumerable
    {
        $source = $this;
        return new self(static function () use ($source, $value): iterable {
            yield $value;
            yield from $source;
        });
    }

    public function concat(iterable $other): IEnumerable
    {
        $source = $this;
        return new self(static function () use ($source, $other): iterable {
            yield from $source;
            yield from $other;
        });
    }

    public function defaultIfEmpty(mixed $default = null): IEnumerable
    {
        $source = $this;
        return new self(static function () use ($source, $default): iterable {
            $found = false;
            foreach ($source as $item) {
                $found = true;
                yield $item;
            }
            if (!$found) {
                yield $default;
            }
        });
    }

    public function zip(iterable $second, callable $resultSelector): IEnumerable
    {
        $source = $this;
        return new self(static function () use ($source, $second, $resultSelector): iterable {
            $iterator = null;
            foreach ($source as $item) {
                if ($iterator === null) {
                    $iterator = new \IteratorIterator(self::from($second)->getIterator());
                    $iterator->rewind();
                } else {
                    $iterator->next();
                }
                if (!$iterator->valid()) {
                    return;
                }
                yield $resultSelector($item, $iterator->current());
            }
        });
    }

    public function sequenceEqual(iterable $second, ?EqualityComparer $comparer = null): bool
    {
        $comparer ??= new DefaultEqualityComparer();
        $iterator = new \IteratorIterator(self::from($second)->getIterator());
        $iterator->rewind();
        foreach ($this as $item) {
            if (!$iterator->valid() || !$comparer->equals($item, $iterator->current())) {
                return false;
            }
            $iterator->next();
        }
        return !$iterator->valid();
    }

    public function elementAt(int $index): mixed
    {
        $sentinel = new \stdClass();
        $value = $this->elementAtOrDefault($index, $sentinel);
        if ($value === $sentinel) {
            throw new \OutOfBoundsException('The index is outside the sequence.');
        }
        return $value;
    }

    public function elementAtOrDefault(int $index, mixed $default = null): mixed
    {
        if ($index < 0) {
            return $default;
        }
        $position = 0;
        foreach ($this as $item) {
            if ($position++ === $index) {
                return $item;
            }
        }
        return $default;
    }

    public function reverse(): IEnumerable
    {
        $source = $this;
        return new self(static fn (): iterable => array_reverse($source->toArray()));
    }

    public function chunk(int $size): IEnumerable
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('Chunk size must be greater than zero.');
        }
        $source = $this;
        return new self(static function () use ($source, $size): iterable {
            $chunk = [];
            foreach ($source as $item) {
                $chunk[] = $item;
                if (count($chunk) === $size) {
                    yield $chunk;
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                yield $chunk;
            }
        });
    }

    public function count(?callable $predicate = null): int
    {
        $count = 0;
        foreach ($this as $item) {
            if ($predicate === null || $predicate($item)) {
                ++$count;
            }
        }
        return $count;
    }

    public function any(?callable $predicate = null): bool
    {
        foreach ($this as $item) {
            if ($predicate === null || $predicate($item)) {
                return true;
            }
        }
        return false;
    }

    public function all(callable $predicate): bool
    {
        foreach ($this as $item) {
            if (!$predicate($item)) {
                return false;
            }
        }
        return true;
    }

    public function contains(mixed $value): bool
    {
        return $this->any(static fn (mixed $item): bool => $item === $value);
    }

    public function first(?callable $predicate = null): mixed
    {
        $sentinel = new \stdClass();
        $value = $this->firstOrDefault($predicate, $sentinel);
        if ($value === $sentinel) {
            throw new \UnderflowException('The sequence contains no matching element.');
        }
        return $value;
    }

    public function firstOrDefault(?callable $predicate = null, mixed $default = null): mixed
    {
        foreach ($this as $item) {
            if ($predicate === null || $predicate($item)) {
                return $item;
            }
        }
        return $default;
    }

    public function last(?callable $predicate = null): mixed
    {
        $sentinel = new \stdClass();
        $value = $this->lastOrDefault($predicate, $sentinel);
        if ($value === $sentinel) {
            throw new \UnderflowException('The sequence contains no matching element.');
        }
        return $value;
    }

    public function lastOrDefault(?callable $predicate = null, mixed $default = null): mixed
    {
        $found = false;
        $result = $default;
        foreach ($this as $item) {
            if ($predicate === null || $predicate($item)) {
                $found = true;
                $result = $item;
            }
        }
        return $found ? $result : $default;
    }

    public function single(?callable $predicate = null): mixed
    {
        $sentinel = new \stdClass();
        $value = $this->singleOrDefault($predicate, $sentinel);
        if ($value === $sentinel) {
            throw new \UnderflowException('The sequence contains no matching element.');
        }
        return $value;
    }

    public function singleOrDefault(?callable $predicate = null, mixed $default = null): mixed
    {
        $found = false;
        $result = $default;
        foreach ($this as $item) {
            if ($predicate !== null && !$predicate($item)) {
                continue;
            }
            if ($found) {
                throw new \UnexpectedValueException('The sequence contains more than one matching element.');
            }
            $found = true;
            $result = $item;
        }
        return $found ? $result : $default;
    }

    public function sum(?callable $selector = null): int|float
    {
        $selector ??= static fn (mixed $item): mixed => $item;
        $sum = 0;
        foreach ($this as $item) {
            $sum += $selector($item);
        }
        return $sum;
    }

    public function average(?callable $selector = null): int|float
    {
        $selector ??= static fn (mixed $item): mixed => $item;
        $sum = 0;
        $count = 0;
        foreach ($this as $item) {
            $sum += $selector($item);
            ++$count;
        }
        if ($count === 0) {
            throw new \UnderflowException('Cannot average an empty sequence.');
        }
        return $sum / $count;
    }

    public function min(?callable $selector = null, ?callable $comparer = null): mixed
    {
        return $this->extreme($selector, $comparer, false, false);
    }

    public function max(?callable $selector = null, ?callable $comparer = null): mixed
    {
        return $this->extreme($selector, $comparer, true, false);
    }

    public function minBy(callable $keySelector, ?callable $comparer = null): mixed
    {
        return $this->extreme($keySelector, $comparer, false, true);
    }

    public function maxBy(callable $keySelector, ?callable $comparer = null): mixed
    {
        return $this->extreme($keySelector, $comparer, true, true);
    }

    public function except(iterable $second, ?EqualityComparer $comparer = null): IEnumerable
    {
        $source = $this;
        return new self(static function () use ($source, $second, $comparer): iterable {
            $excluded = new HashSet($second, $comparer);
            foreach ($source as $item) {
                if ($excluded->tryAdd($item)) {
                    yield $item;
                }
            }
        });
    }

    public function intersect(iterable $second, ?EqualityComparer $comparer = null): IEnumerable
    {
        $source = $this;
        return new self(static function () use ($source, $second, $comparer): iterable {
            $remaining = new HashSet($second, $comparer);
            foreach ($source as $item) {
                if ($remaining->remove($item)) {
                    yield $item;
                }
            }
        });
    }

    public function union(iterable $second, ?EqualityComparer $comparer = null): IEnumerable
    {
        $source = $this;
        return new self(static function () use ($source, $second, $comparer): iterable {
            $seen = new HashSet([], $comparer);
            foreach ([$source, $second] as $sequence) {
                foreach ($sequence as $item) {
                    if ($seen->tryAdd($item)) {
                        yield $item;
                    }
                }
            }
        });
    }

    public function exceptBy(iterable $second, callable $keySelector, ?EqualityComparer $comparer = null): IEnumerable
    {
        $source = $this;
        return new self(static function () use ($source, $second, $keySelector, $comparer): iterable {
            $excluded = new HashSet($second, $comparer);
            foreach ($source as $item) {
                if ($excluded->tryAdd($keySelector($item))) {
                    yield $item;
                }
            }
        });
    }

    public function intersectBy(iterable $second, callable $keySelector, ?EqualityComparer $comparer = null): IEnumerable
    {
        $source = $this;
        return new self(static function () use ($source, $second, $keySelector, $comparer): iterable {
            $remaining = new HashSet($second, $comparer);
            foreach ($source as $item) {
                if ($remaining->remove($keySelector($item))) {
                    yield $item;
                }
            }
        });
    }

    public function unionBy(iterable $second, callable $keySelector, ?EqualityComparer $comparer = null): IEnumerable
    {
        $source = $this;
        return new self(static function () use ($source, $second, $keySelector, $comparer): iterable {
            $seen = new HashSet([], $comparer);
            foreach ([$source, $second] as $sequence) {
                foreach ($sequence as $item) {
                    if ($seen->tryAdd($keySelector($item))) {
                        yield $item;
                    }
                }
            }
        });
    }

    public function countBy(callable $keySelector, ?EqualityComparer $comparer = null): IEnumerable
    {
        return $this->aggregateBy($keySelector, 0, static fn (int $count, mixed $item): int => $count + 1, $comparer);
    }

    public function aggregateBy(
        callable $keySelector,
        mixed $seed,
        callable $accumulator,
        ?EqualityComparer $comparer = null,
    ): IEnumerable {
        $source = $this;
        return new self(static function () use ($source, $keySelector, $seed, $accumulator, $comparer): iterable {
            $comparer ??= new DefaultEqualityComparer();
            $groups = [];
            $buckets = [];
            foreach ($source as $item) {
                $key = $keySelector($item);
                $hash = $comparer->hash($key);
                foreach ($buckets[$hash] ?? [] as $index) {
                    if ($comparer->equals($groups[$index]['key'], $key)) {
                        $groups[$index]['value'] = $accumulator($groups[$index]['value'], $item);
                        continue 2;
                    }
                }
                $buckets[$hash][] = count($groups);
                $groups[] = ['key' => $key, 'value' => $accumulator($seed, $item)];
            }
            foreach ($groups as $group) {
                yield new KeyValuePair($group['key'], $group['value']);
            }
        });
    }

    public function shuffle(): IEnumerable
    {
        $source = $this;
        return new self(static function () use ($source): iterable {
            $items = $source->toArray();
            shuffle($items);
            yield from $items;
        });
    }

    public function join(
        iterable $inner,
        callable $outerKeySelector,
        callable $innerKeySelector,
        callable $resultSelector,
        ?EqualityComparer $comparer = null,
    ): IEnumerable {
        $source = $this;
        return new self(static function () use (
            $source,
            $inner,
            $outerKeySelector,
            $innerKeySelector,
            $resultSelector,
            $comparer,
        ): iterable {
            $comparer ??= new DefaultEqualityComparer();
            $lookup = [];
            foreach ($inner as $innerItem) {
                $key = $innerKeySelector($innerItem);
                $lookup[$comparer->hash($key)][] = ['key' => $key, 'item' => $innerItem];
            }
            foreach ($source as $outerItem) {
                $outerKey = $outerKeySelector($outerItem);
                foreach ($lookup[$comparer->hash($outerKey)] ?? [] as $candidate) {
                    if ($comparer->equals($outerKey, $candidate['key'])) {
                        yield $resultSelector($outerItem, $candidate['item']);
                    }
                }
            }
        });
    }

    public function aggregate(callable $accumulator, mixed $seed = null): mixed
    {
        $hasSeed = func_num_args() >= 2;
        $result = $seed;
        foreach ($this as $item) {
            if (!$hasSeed) {
                $result = $item;
                $hasSeed = true;
                continue;
            }
            $result = $accumulator($result, $item);
        }
        if (!$hasSeed) {
            throw new \UnderflowException('Cannot aggregate an empty sequence without a seed.');
        }
        return $result;
    }

    public function toArray(): array
    {
        return iterator_to_array($this->getIterator(), false);
    }

    private function extreme(
        ?callable $selector,
        ?callable $comparer,
        bool $maximum,
        bool $returnItem,
    ): mixed {
        $selector ??= static fn (mixed $item): mixed => $item;
        $comparer ??= static fn (mixed $left, mixed $right): int => $left <=> $right;
        $found = false;
        $bestItem = null;
        $bestKey = null;

        foreach ($this as $item) {
            $key = $selector($item);
            if (!$found) {
                $found = true;
                $bestItem = $item;
                $bestKey = $key;
                continue;
            }
            $comparison = $comparer($key, $bestKey);
            if ($maximum ? $comparison > 0 : $comparison < 0) {
                $bestItem = $item;
                $bestKey = $key;
            }
        }

        if (!$found) {
            throw new \UnderflowException('The sequence contains no elements.');
        }
        return $returnItem ? $bestItem : $bestKey;
    }

    private static function assertNonNegative(int $count): void
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('Count cannot be negative.');
        }
    }
}
