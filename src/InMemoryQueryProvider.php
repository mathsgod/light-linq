<?php

declare(strict_types=1);

namespace Light\Linq;

use Light\Linq\Expression\BinaryExpression;
use Light\Linq\Expression\ConstantExpression;
use Light\Linq\Expression\Expression;
use Light\Linq\Expression\FieldExpression;
use Light\Linq\Expression\LogicalExpression;
use Light\Linq\Expression\MethodCallExpression;
use Light\Linq\Expression\ProjectionExpression;
use Light\Linq\Expression\SourceExpression;
use Light\Linq\Expression\ValueExpression;

final class InMemoryQueryProvider implements IQueryProvider
{
    /** @param array<string, iterable<mixed>> $sources */
    public function __construct(private readonly array $sources)
    {
    }

    public function execute(Expression $expression): mixed
    {
        if ($expression instanceof SourceExpression) {
            if (!array_key_exists($expression->name, $this->sources)) {
                throw new \OutOfBoundsException("Unknown query source: {$expression->name}");
            }
            return $this->sources[$expression->name];
        }

        if (!$expression instanceof MethodCallExpression) {
            throw new \LogicException('The provider expected a query expression.');
        }

        if (in_array($expression->method, ['orderBy', 'thenBy'], true)) {
            $keys = [];
            $node = $expression;
            while ($node instanceof MethodCallExpression && $node->method === 'thenBy') {
                array_unshift($keys, $node->arguments);
                $node = $node->source;
            }
            if (!$node instanceof MethodCallExpression || $node->method !== 'orderBy') {
                throw new \LogicException('thenBy() requires a preceding orderBy().');
            }
            array_unshift($keys, $node->arguments);
            $items = iterator_to_array($this->iterator($this->execute($node->source)), false);
            usort($items, function ($left, $right) use ($keys): int {
                foreach ($keys as $key) {
                    $comparison = $this->evaluate($key['keySelector'], $left) <=> $this->evaluate($key['keySelector'], $right);
                    if ($comparison !== 0) return $key['descending'] ? -$comparison : $comparison;
                }
                return 0;
            });
            return $items;
        }
        $source = $this->execute($expression->source);

        return match ($expression->method) {
            'join', 'leftJoin' => $this->joinRows($source, $expression),
            'groupBy' => $this->groupRows($source, $expression->arguments['keySelector']),
            'distinct' => $this->distinctRows($source),
            'where' => $this->where($source, $expression->arguments['predicate']),
            'select' => $this->select($source, $expression->arguments['selector']),
            'skip' => $this->skip($source, $expression->arguments['count']),
            'take' => $this->take($source, $expression->arguments['count']),
            'firstOrDefault' => $this->terminalRows($source, $expression, false),
            'single' => $this->terminalRows($source, $expression, true),
            'all' => $this->allRows($source, $expression->arguments['predicate']),
            'contains' => Enumerable::from($source)->contains($expression->arguments['value']),
            'average', 'min', 'max' => $this->terminalAggregate($source, $expression),
            'sum' => Enumerable::from($source)->sum(fn ($row) => $this->evaluate($expression->arguments['selector'], $row)),
            'count' => iterator_count($this->iterator($source)),
            'any' => $this->any($source),
            'first' => $this->first($source),
            default => throw new \LogicException("Unsupported query method: {$expression->method}"),
        };
    }

    private function terminalAggregate(iterable $source, MethodCallExpression $expression): mixed
    {
        $result = null;
        $count = 0;
        $sum = 0;
        foreach ($source as $row) {
            $value = $this->evaluate($expression->arguments['selector'], $row);
            if ($value === null) continue;
            if ($expression->method === 'average') $sum += $value;
            elseif ($count === 0) $result = $value;
            elseif ($expression->method === 'min') $result = min($result, $value);
            else $result = max($result, $value);
            ++$count;
        }
        if ($count === 0) return null;
        return $expression->method === 'average' ? $sum / $count : $result;
    }

    private function terminalRows(iterable $source, MethodCallExpression $expression, bool $single): mixed
    {
        $predicate = $expression->arguments['predicate'];
        $rows = Enumerable::from($predicate === null ? $source : $this->where($source, $predicate));
        return $single ? $rows->single() : $rows->firstOrDefault(default: $expression->arguments['default']);
    }
    private function allRows(iterable $source, ValueExpression $predicate): bool
    {
        foreach ($source as $row) {
            if (!$this->evaluate($predicate, $row)) return false;
        }
        return true;
    }
    private function groupRows(iterable $source, ValueExpression $selector): array
    {
        $keys = $items = [];
        foreach ($source as $row) {
            $key = $this->evaluate($selector, $row);
            $index = array_search($key, $keys, true);
            if ($index === false) {
                $index = count($keys);
                $keys[] = $key;
                $items[$index] = [];
            }
            $items[$index][] = $row;
        }
        return array_map(static fn ($key, $rows) => new Grouping($key, $rows), $keys, $items);
    }
    private function distinctRows(iterable $source): \Generator
    {
        $seen = [];
        foreach ($source as $row) {
            if (!in_array($row, $seen, true)) {
                $seen[] = $row;
                yield $row;
            }
        }
    }
    private function groupKey(\Light\Linq\Expression\GroupKeyExpression $expression, mixed $row): mixed
    {
        if (!$row instanceof Grouping) {
            throw new \LogicException('Group keys require a grouping.');
        }
        return $expression->member === null ? $row->key : $this->readField($row->key, $expression->member);
    }
    private function aggregate(\Light\Linq\Expression\AggregateExpression $expression, mixed $row): mixed
    {
        if (!$row instanceof Grouping) {
            throw new \LogicException('Aggregates require a grouping.');
        }
        if ($expression->operand === null) {
            return count($row);
        }
        $values = [];
        foreach ($row as $item) {
            $value = $this->evaluate($expression->operand, $item);
            if ($value !== null) $values[] = $value;
        }
        if ($expression->function === 'count') return count($values);
        if ($values === []) return null;
        return match ($expression->function) {
            'sum' => array_sum($values),
            'avg' => array_sum($values) / count($values),
            'min' => min($values),
            'max' => max($values),
        };
    }
    private function joinRows(iterable $source, MethodCallExpression $expression): \Generator
    {
        $arguments = $expression->arguments;
        $inner = iterator_to_array($this->iterator($this->execute($arguments['inner'])), false);
        foreach ($source as $outer) {
            $key = $this->evaluate($arguments['outerKey'], $outer);
            $matched = false;
            foreach ($inner as $item) {
                $innerKey = $this->evaluate($arguments['innerKey'], $item);
                // Relational equality does not match null join keys.
                if ($this->joinKeysEqual($key, $innerKey)) {
                    $matched = true;
                    yield $this->evaluate($arguments['resultSelector'], ['outer' => $outer, 'inner' => $item]);
                }
            }
            if (!$matched && $expression->method === 'leftJoin') {
                yield $this->evaluate($arguments['resultSelector'], ['outer' => $outer, 'inner' => null]);
            }
        }
    }

    private function joinKeysEqual(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return false;
        }
        if (is_array($left) && is_array($right)) {
            if (array_keys($left) !== array_keys($right)) {
                return false;
            }
            foreach ($left as $name => $value) {
                if (!$this->joinKeysEqual($value, $right[$name])) {
                    return false;
                }
            }
            return true;
        }
        return $left === $right;
    }

    private function where(iterable $source, ValueExpression $predicate): \Generator
    {
        foreach ($source as $item) {
            if ((bool) $this->evaluate($predicate, $item)) {
                yield $item;
            }
        }
    }

    private function select(iterable $source, ValueExpression $selector): \Generator
    {
        foreach ($source as $item) {
            yield $this->evaluate($selector, $item);
        }
    }

    private function skip(iterable $source, int $count): \Generator
    {
        $seen = 0;
        foreach ($source as $item) {
            if ($seen++ >= $count) {
                yield $item;
            }
        }
    }

    private function take(iterable $source, int $count): \Generator
    {
        if ($count === 0) {
            return;
        }

        $seen = 0;
        foreach ($source as $item) {
            yield $item;
            if (++$seen >= $count) {
                return;
            }
        }
    }

    private function any(iterable $source): bool
    {
        foreach ($source as $_) {
            return true;
        }
        return false;
    }

    private function first(iterable $source): mixed
    {
        foreach ($source as $item) {
            return $item;
        }
        throw new \UnderflowException('The query contains no elements.');
    }

    private function evaluate(ValueExpression $expression, mixed $row): mixed
    {
        return match (true) {
            $expression instanceof \Light\Linq\Expression\GroupKeyExpression => $this->groupKey($expression, $row),
            $expression instanceof \Light\Linq\Expression\AggregateExpression => $this->aggregate($expression, $row),
            $expression instanceof ConstantExpression => $expression->value,
            $expression instanceof FieldExpression => $this->readField($row, $expression->path),
            $expression instanceof BinaryExpression => $this->evaluateBinary($expression, $row),
            $expression instanceof LogicalExpression => $this->evaluateLogical($expression, $row),
            $expression instanceof ProjectionExpression => $this->evaluateProjection($expression, $row),
            default => throw new \LogicException('Unsupported value expression: '.$expression::class),
        };
    }

    /** @return array<string, mixed> */
    private function evaluateProjection(ProjectionExpression $expression, mixed $row): array
    {
        $result = [];
        foreach ($expression->members as $alias => $member) {
            $result[$alias] = $this->evaluate($member, $row);
        }
        return $result;
    }

    private function evaluateBinary(BinaryExpression $expression, mixed $row): bool
    {
        $left = $this->evaluate($expression->left, $row);
        $right = $this->evaluate($expression->right, $row);
        return match ($expression->operator) {
            '=' => $left === $right,
            '!=' => $left !== $right,
            '>' => $left > $right,
            '>=' => $left >= $right,
            '<' => $left < $right,
            '<=' => $left <= $right,
        };
    }

    private function evaluateLogical(LogicalExpression $expression, mixed $row): bool
    {
        return $expression->operator === 'and'
            ? (bool) $this->evaluate($expression->left, $row)
                && (bool) $this->evaluate($expression->right, $row)
            : (bool) $this->evaluate($expression->left, $row)
                || (bool) $this->evaluate($expression->right, $row);
    }

    private function readField(mixed $row, string $path): mixed
    {
        $value = $row;
        foreach (explode('.', $path) as $segment) {
            if ($value === null) {
                return null;
            }
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
                continue;
            }
            if (is_object($value) && (isset($value->{$segment}) || array_key_exists($segment, get_object_vars($value)))) {
                $value = $value->{$segment};
                continue;
            }
            throw new \OutOfBoundsException("Cannot read field path: {$path}");
        }
        return $value;
    }

    private function iterator(iterable $source): \Traversable
    {
        yield from $source;
    }
}
