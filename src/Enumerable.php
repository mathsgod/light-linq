<?php

declare(strict_types=1);

namespace Light\Linq;

use Closure;
use Light\Collections\Generic\DefaultEqualityComparer;
use Light\Collections\Generic\Dictionary;
use Light\Collections\Generic\EqualityComparer;
use Light\Collections\Generic\GenericList;
use Light\Collections\Generic\HashSet;
use Light\Collections\Generic\KeyValuePair;
use Traversable;

/** @template T @implements IEnumerable<T> */
class Enumerable implements IEnumerable
{
    /** @var Closure(): iterable<T> */
    private readonly Closure $factory;

    /** @param callable(): iterable<T> $factory */
    protected function __construct(callable $factory, private readonly ?Closure $countFactory = null)
    {
        $this->factory = Closure::fromCallable($factory);
    }

    /** @template TSource @param iterable<TSource> $source @return IEnumerable<TSource> */
    public static function from(iterable $source): IEnumerable
    {
        if ($source instanceof IEnumerable) {
            return $source;
        }
        $countFactory = null;
        if (is_array($source)
            || $source instanceof GenericList
            || $source instanceof HashSet
            || $source instanceof Dictionary
            || $source instanceof Grouping
            || $source instanceof Lookup
        ) {
            $countFactory = static fn (): int => count($source);
        }
        return new self(static fn (): iterable => $source, $countFactory);
    }

    /** @template TSource @param callable(): iterable<TSource> $factory @return IEnumerable<TSource> */
    public static function defer(callable $factory): IEnumerable
    {
        return new self($factory);
    }

    /** @return IEnumerable<never> */
    public static function empty(): IEnumerable
    {
        return new self(static fn (): iterable => [], static fn (): int => 0);
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
        }, static fn (): int => $count);
    }

    /** @return IEnumerable<int|float> */
    public static function sequence(int|float $start, int|float $endInclusive, int|float $step): IEnumerable
    {
        self::assertFiniteNumbers($start, $endInclusive, $step);
        if (($step > 0 && $endInclusive < $start)
            || ($step < 0 && $endInclusive > $start)
            || ($step == 0 && $start != $endInclusive)
        ) {
            throw new \InvalidArgumentException('The step must move towards the end of the sequence.');
        }
        return new self(static function () use ($start, $endInclusive, $step): iterable {
            $current = $start;
            while (true) {
                yield $current;
                if ($current == $endInclusive) {
                    return;
                }
                $next = self::nextSequenceValue($current, $step);
                if ($next === null || ($step > 0 ? $next > $endInclusive : $next < $endInclusive)) {
                    return;
                }
                $current = $next;
            }
        });
    }

    /** @return IEnumerable<int|float> */
    public static function infiniteSequence(int|float $start, int|float $step): IEnumerable
    {
        self::assertFiniteNumbers($start, $step);
        return new self(static function () use ($start, $step): iterable {
            $current = $start;
            while (true) {
                yield $current;
                if ($step == 0) {
                    continue;
                }
                $current = self::nextSequenceValue($current, $step)
                    ?? throw new \OverflowException('The sequence cannot advance within the PHP numeric range and precision.');
            }
        });
    }

    private static function assertFiniteNumbers(int|float ...$values): void
    {
        foreach ($values as $value) {
            if (is_float($value) && !is_finite($value)) {
                throw new \InvalidArgumentException('Sequence arguments must be finite numbers.');
            }
        }
    }

    private static function nextSequenceValue(int|float $current, int|float $step): int|float|null
    {
        if (is_int($current) && is_int($step)
            && (($step > 0 && $current > PHP_INT_MAX - $step)
                || ($step < 0 && $current < PHP_INT_MIN - $step))
        ) {
            return null;
        }
        $next = $current + $step;
        if ((is_float($next) && !is_finite($next))
            || ($step > 0 ? $next <= $current : $next >= $current)
        ) {
            return null;
        }
        return $next;
    }

    /** @template TElement @param TElement $value @return IEnumerable<TElement> */
    public static function repeat(mixed $value, int $count): IEnumerable
    {
        self::assertNonNegative($count);
        return new self(static function () use ($value, $count): iterable {
            for ($index = 0; $index < $count; ++$index) {
                yield $value;
            }
        }, static fn (): int => $count);
    }

    public function getIterator(): Traversable
    {
        yield from ($this->factory)();
    }

    /** @return IEnumerable<array{index: int, value: T}> */
    public function index(): IEnumerable
    {
        $source = $this;
        return new self(static function () use ($source): iterable {
            $index = 0;
            foreach ($source as $item) {
                yield ['index' => $index, 'value' => $item];
                ++$index;
            }
        });
    }

    public function cast(string $type): IEnumerable
    {
        $matches = self::typeMatcher($type);
        return $this->select(static function (mixed $item) use ($matches, $type): mixed {
            if (!$matches($item)) {
                throw new \UnexpectedValueException('Expected '.$type.', got '.get_debug_type($item).'.');
            }
            return $item;
        });
    }

    public function ofType(string $type): IEnumerable
    {
        return $this->where(self::typeMatcher($type));
    }

    /** @return Closure(mixed): bool */
    private static function typeMatcher(string $type): Closure
    {
        $predicate = match ($type) {
            'int' => 'is_int',
            'float' => 'is_float',
            'string' => 'is_string',
            'bool' => 'is_bool',
            'array' => 'is_array',
            'object' => 'is_object',
            'iterable' => 'is_iterable',
            'callable' => 'is_callable',
            'resource' => 'is_resource',
            default => null,
        };
        if ($predicate !== null) {
            return Closure::fromCallable($predicate);
        }
        if (!class_exists($type) && !interface_exists($type)) {
            throw new \InvalidArgumentException('Unsupported element type: '.$type);
        }
        return static fn (mixed $item): bool => $item instanceof $type;
    }

    public function select(callable $selector): IEnumerable
    {
        $selector = self::indexedCallback($selector);
        $source = $this;
        return new self(static function () use ($source, $selector): iterable {
            $index = 0;
            foreach ($source as $item) {
                yield $selector($item, $index++);
            }
        });
    }

    public function where(callable $predicate): IEnumerable
    {
        $predicate = self::indexedCallback($predicate);
        $source = $this;
        return new self(static function () use ($source, $predicate): iterable {
            $index = 0;
            foreach ($source as $item) {
                if ($predicate($item, $index++)) {
                    yield $item;
                }
            }
        });
    }

    public function selectMany(callable $selector, ?callable $resultSelector = null): IEnumerable
    {
        $selector = self::indexedCallback($selector);
        $source = $this;
        return new self(static function () use ($source, $selector, $resultSelector): iterable {
            $index = 0;
            foreach ($source as $item) {
                $inner = $selector($item, $index++);
                if ($resultSelector === null) {
                    yield from $inner;
                    continue;
                }
                foreach ($inner as $innerItem) {
                    yield $resultSelector($item, $innerItem);
                }
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

    public function order(?callable $comparer = null): IOrderedEnumerable
    {
        return $this->orderBy(static fn (mixed $item): mixed => $item, $comparer);
    }

    public function orderDescending(?callable $comparer = null): IOrderedEnumerable
    {
        return $this->orderByDescending(static fn (mixed $item): mixed => $item, $comparer);
    }

    public function orderBy(callable $keySelector, ?callable $comparer = null): IOrderedEnumerable
    {
        return new OrderedEnumerable($this, [[$keySelector, false, $comparer]]);
    }

    public function orderByDescending(callable $keySelector, ?callable $comparer = null): IOrderedEnumerable
    {
        return new OrderedEnumerable($this, [[$keySelector, true, $comparer]]);
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
        $predicate = self::indexedCallback($predicate);
        $source = $this;
        return new self(static function () use ($source, $predicate): iterable {
            $yielding = false;
            $index = 0;
            foreach ($source as $item) {
                $yielding = $yielding || !$predicate($item, $index);
                ++$index;
                if ($yielding) {
                    yield $item;
                }
            }
        });
    }

    public function takeWhile(callable $predicate): IEnumerable
    {
        $predicate = self::indexedCallback($predicate);
        $source = $this;
        return new self(static function () use ($source, $predicate): iterable {
            $index = 0;
            foreach ($source as $item) {
                if (!$predicate($item, $index++)) {
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
        return $this->distinctBy($keySelector ?? static fn (mixed $item): mixed => $item);
    }

    /**
     * @template TKey
     * @param callable(T): TKey $keySelector
     * @param EqualityComparer<TKey>|null $comparer
     * @return IEnumerable<T>
     */
    public function distinctBy(callable $keySelector, ?EqualityComparer $comparer = null): IEnumerable
    {
        $source = $this;
        return new self(static function () use ($source, $keySelector, $comparer): iterable {
            $seen = new HashSet([], $comparer);
            foreach ($source as $item) {
                if ($seen->tryAdd($keySelector($item))) {
                    yield $item;
                }
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

    public function tryGetNonEnumeratedCount(?int &$count): bool
    {
        $count = 0;
        if ($this->countFactory === null) {
            return false;
        }
        $count = ($this->countFactory)();
        return true;
    }

    public function asEnumerable(): IEnumerable
    {
        return $this;
    }

    public function longCount(?callable $predicate = null): int
    {
        return $this->count($predicate);
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

    public function contains(mixed $value, ?EqualityComparer $comparer = null): bool
    {
        $comparer ??= new DefaultEqualityComparer();
        return $this->any(static fn (mixed $item): bool => $comparer->equals($item, $value));
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

    /**
     * @template TInner
     * @template TKey
     * @template TResult
     * @param iterable<TInner> $inner
     * @param callable(T): TKey $outerKeySelector
     * @param callable(TInner): TKey $innerKeySelector
     * @param callable(T, TInner|null): TResult $resultSelector
     * @param EqualityComparer<TKey>|null $comparer
     * @return IEnumerable<TResult>
     */
    public function leftJoin(
        iterable $inner,
        callable $outerKeySelector,
        callable $innerKeySelector,
        callable $resultSelector,
        ?EqualityComparer $comparer = null,
    ): IEnumerable {
        return $this->groupJoin(
            $inner,
            $outerKeySelector,
            $innerKeySelector,
            static fn ($outer, IEnumerable $matches): IEnumerable => $matches->defaultIfEmpty()
                ->select(static fn ($item) => $resultSelector($outer, $item)),
            $comparer,
        )->selectMany(static fn (IEnumerable $matches): iterable => $matches);
    }

    /**
     * @template TInner
     * @template TKey
     * @template TResult
     * @param iterable<TInner> $inner
     * @param callable(T): TKey $outerKeySelector
     * @param callable(TInner): TKey $innerKeySelector
     * @param callable(T|null, TInner): TResult $resultSelector
     * @param EqualityComparer<TKey>|null $comparer
     * @return IEnumerable<TResult>
     */
    public function rightJoin(
        iterable $inner,
        callable $outerKeySelector,
        callable $innerKeySelector,
        callable $resultSelector,
        ?EqualityComparer $comparer = null,
    ): IEnumerable {
        return self::from($inner)->leftJoin(
            $this,
            $innerKeySelector,
            $outerKeySelector,
            static fn ($right, $left) => $resultSelector($left, $right),
            $comparer,
        );
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

    /** @return GenericList<T> */
    public function toList(): GenericList
    {
        return new GenericList($this);
    }

    /** @param EqualityComparer<T>|null $comparer @return HashSet<T> */
    public function toHashSet(?EqualityComparer $comparer = null): HashSet
    {
        return new HashSet($this, $comparer);
    }

    /**
     * @template TKey
     * @template TValue
     * @param callable(T): TKey $keySelector
     * @param (callable(T): TValue)|null $elementSelector Defaults to the source element.
     * @param EqualityComparer<TKey>|null $comparer
     * @return Dictionary<TKey, TValue|T>
     * @throws \InvalidArgumentException If a selected key is null or duplicated.
     */
    public function toDictionary(
        callable $keySelector,
        ?callable $elementSelector = null,
        ?EqualityComparer $comparer = null,
    ): Dictionary {
        $dictionary = new Dictionary($comparer);
        foreach ($this as $item) {
            $key = $keySelector($item);
            $value = $elementSelector === null ? $item : $elementSelector($item);
            $dictionary->add($key, $value);
        }
        return $dictionary;
    }

    /**
     * @template TKey
     * @template TElement
     * @param callable(T): TKey $keySelector
     * @param (callable(T): TElement)|null $elementSelector Defaults to the source element.
     * @param EqualityComparer<TKey>|null $comparer
     * @return Lookup<TKey, TElement|T>
     */
    public function toLookup(
        callable $keySelector,
        ?callable $elementSelector = null,
        ?EqualityComparer $comparer = null,
    ): Lookup {
        return new Lookup($this, $keySelector, $elementSelector, $comparer);
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

    /** @return Closure(mixed, int): mixed */
    private static function indexedCallback(callable $callback): Closure
    {
        $closure = Closure::fromCallable($callback);
        $reflection = new \ReflectionFunction($closure);
        if (!$reflection->isInternal()
            && ($reflection->getNumberOfParameters() >= 2 || $reflection->isVariadic())
        ) {
            return $closure;
        }
        return static fn (mixed $item, int $index): mixed => $closure($item);
    }

    private static function assertNonNegative(int $count): void
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('Count cannot be negative.');
        }
    }
}
