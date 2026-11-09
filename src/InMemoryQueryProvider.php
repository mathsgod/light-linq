<?php

declare(strict_types=1);

namespace Light\Linq;

use Light\Linq\Expressions\BinaryExpression;
use Light\Linq\Expressions\ConstantExpression;
use Light\Linq\Expressions\Expression;
use Light\Linq\Expressions\FieldExpression;
use Light\Linq\Expressions\LogicalExpression;
use Light\Linq\Expressions\MethodCallExpression;
use Light\Linq\Expressions\ProjectionExpression;
use Light\Linq\Expressions\SourceExpression;
use Light\Linq\Expressions\ValueExpression;

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

        if (in_array($expression->method, ['order', 'orderBy', 'thenBy'], true)) {
            $keys = [];
            $node = $expression;
            while ($node instanceof MethodCallExpression && $node->method === 'thenBy') {
                array_unshift($keys, $node->arguments);
                $node = $node->source;
            }
            if (!$node instanceof MethodCallExpression || !in_array($node->method, ['order', 'orderBy'], true)) {
                throw new \LogicException('thenBy() requires a preceding order() or orderBy().');
            }
            array_unshift($keys, $node->method === 'order'
                ? ['keySelector' => null, 'descending' => $node->arguments['descending']]
                : $node->arguments);
            $items = iterator_to_array($this->iterator($this->execute($node->source)), false);
            usort($items, function ($left, $right) use ($keys): int {
                foreach ($keys as $key) {
                    $comparison = $key['keySelector'] === null
                        ? $left <=> $right
                        : $this->evaluate($key['keySelector'], $left) <=> $this->evaluate($key['keySelector'], $right);
                    if ($comparison !== 0) return $key['descending'] ? -$comparison : $comparison;
                }
                return 0;
            });
            return $items;
        }
        if ($expression->method === 'rightJoin') {
            return $this->rightJoinRows($expression);
        }
        $source = $this->execute($expression->source);

        return match ($expression->method) {
            'aggregate' => $this->aggregateRows($source, $expression),
            'aggregateBy' => Enumerable::from($source)->aggregateBy(
                fn (mixed $row): mixed => $this->evaluate($expression->arguments['keySelector'], $row),
                $expression->arguments['seed'],
                fn (mixed $accumulator, mixed $item): mixed => $this->evaluate($expression->arguments['accumulator'], ['accumulator' => $accumulator, 'item' => $item]),
            ),
            'countBy' => Enumerable::from($source)->countBy(
                fn (mixed $row): mixed => $this->evaluate($expression->arguments['keySelector'], $row),
            ),
            'elementAt' => Enumerable::from($source)->elementAt($expression->arguments['index']),
            'elementAtOrDefault' => Enumerable::from($source)->elementAtOrDefault(
                $expression->arguments['index'], $expression->arguments['default'],
            ),
            'distinctBy', 'exceptBy', 'intersectBy', 'unionBy' => $this->keyRows($source, $expression),
            'minBy', 'maxBy' => Enumerable::from($source)->{$expression->method}(
                fn (mixed $row): mixed => $this->evaluate($expression->arguments['keySelector'], $row),
            ),
            'append' => Enumerable::from($source)->append($expression->arguments['value']),
            'prepend' => Enumerable::from($source)->prepend($expression->arguments['value']),
            'reverse' => Enumerable::from($source)->reverse(),
            'defaultIfEmpty' => Enumerable::from($source)->defaultIfEmpty($expression->arguments['default']),
            'skipLast' => Enumerable::from($source)->skipLast($expression->arguments['count']),
            'takeLast' => Enumerable::from($source)->takeLast($expression->arguments['count']),
            'skipWhile', 'takeWhile' => Enumerable::from($source)->{$expression->method}(
                fn (mixed $row, int $index): bool => (bool) $this->evaluateIndexed($expression->arguments['predicate'], $row, $index),
            ),
            'concat', 'union', 'intersect', 'except' => $this->setRows($source, $expression),
            'selectMany' => $this->flattenRows($source, $expression),
            'join', 'leftJoin' => $this->joinRows($source, $expression),
            'groupJoin' => $this->groupJoinRows($source, $expression),
            'zip' => $this->zipRows($source, $expression),
            'chunk' => Enumerable::from($source)->chunk($expression->arguments['size']),
            'groupBy' => $this->groupRows($source, $expression->arguments['keySelector']),
            'distinct' => $this->distinctRows($source),
            'where' => $this->where($source, $expression->arguments['predicate']),
            'select' => $this->select($source, $expression->arguments['selector']),
            'skip' => $this->skip($source, $expression->arguments['count']),
            'take' => $this->take($source, $expression->arguments['count']),
            'firstOrDefault', 'single', 'singleOrDefault', 'last', 'lastOrDefault' => $this->terminalRows($source, $expression),
            'all' => $this->allRows($source, $expression->arguments['predicate']),
            'contains' => Enumerable::from($source)->any(static fn (mixed $item): bool => $item === $expression->arguments['value']),
            'average', 'min', 'max' => $this->terminalAggregate($source, $expression),
            'sum' => Enumerable::from($source)->sum(fn ($row) => $this->evaluate($expression->arguments['selector'], $row)),
            'count' => iterator_count($this->iterator($source)),
            'any' => $this->any($source),
            'first' => $this->first($source),
            default => throw new \LogicException("Unsupported query method: {$expression->method}"),
        };
    }

    private function aggregateRows(iterable $source, MethodCallExpression $expression): mixed
    {
        $arguments = $expression->arguments;
        $accumulator = fn (mixed $accumulator, mixed $item): mixed => $this->evaluate(
            $arguments['accumulator'], ['accumulator' => $accumulator, 'item' => $item],
        );
        $sequence = Enumerable::from($source);
        $result = $arguments['hasSeed'] ? $sequence->aggregate($accumulator, $arguments['seed']) : $sequence->aggregate($accumulator);
        return $arguments['resultSelector'] === null ? $result : $this->evaluate($arguments['resultSelector'], ['result' => $result]);
    }

    private function keyRows(iterable $source, MethodCallExpression $expression): \Generator
    {
        $keySelector = fn (mixed $row): mixed => $this->evaluate($expression->arguments['keySelector'], $row);
        if ($expression->method === 'distinctBy') {
            yield from Enumerable::from($source)->distinctBy($keySelector);
            return;
        }
        $second = (function () use ($expression): \Generator {
            yield from $this->execute($expression->arguments['second']);
        })();
        yield from Enumerable::from($source)->{$expression->method}($second, $keySelector);
    }

    private function setRows(iterable $source, MethodCallExpression $expression): \Generator
    {
        $second = (function () use ($expression): \Generator {
            yield from $this->execute($expression->arguments['second']);
        })();
        $method = $expression->method;
        yield from Enumerable::from($source)->$method($second);
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

    private function terminalRows(iterable $source, MethodCallExpression $expression): mixed
    {
        $predicate = $expression->arguments['predicate'];
        $rows = Enumerable::from($predicate === null ? $source : $this->where($source, $predicate));
        $method = $expression->method;
        return str_ends_with($method, 'OrDefault')
            ? $rows->$method(default: $expression->arguments['default']) : $rows->$method();
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
    private function groupKey(\Light\Linq\Expressions\GroupKeyExpression $expression, mixed $row): mixed
    {
        if (!$row instanceof Grouping) {
            throw new \LogicException('Group keys require a grouping.');
        }
        return $expression->member === null ? $row->key : $this->readField($row->key, $expression->member);
    }
    private function aggregate(\Light\Linq\Expressions\AggregateExpression $expression, mixed $row): mixed
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
    private function flattenRows(iterable $source, MethodCallExpression $expression): \Generator
    {
        $arguments = $expression->arguments;
        $inner = null;
        $index = 0;
        foreach ($source as $outer) {
            // Materialize once for repeated enumeration, but do not read an inner source
            // at all if the outer sequence is empty.
            if (isset($arguments['selector'])) {
                $items = $this->evaluateIndexed($arguments['selector'], $outer, $index);
                if (!is_iterable($items)) {
                    throw new \UnexpectedValueException('selectMany() collection selector must return an iterable.');
                }
            } else {
                $inner ??= iterator_to_array($this->iterator($this->execute($arguments['inner'])), false);
                $items = $inner;
            }
            foreach ($items as $item) {
                $pair = ['outer' => $outer, 'inner' => $item];
                if ($arguments['predicate'] !== null
                    && $this->collectionPredicate($this->bindOuterFields($arguments['predicate'], null, 0, $index, false), $pair) !== true) continue;
                yield $arguments['resultSelector'] === null ? $item : $this->evaluateIndexed($arguments['resultSelector'], $pair, $index);
            }
            ++$index;
        }
    }

    /** Match SQL WHERE/ON truth values, including UNKNOWN for nullable comparisons. */
    private function collectionPredicate(ValueExpression $expression, array $pair): ?bool
    {
        if ($expression instanceof BinaryExpression) {
            $left = $this->evaluate($expression->left, $pair);
            $right = $this->evaluate($expression->right, $pair);
            $nullTest = in_array($expression->operator, ['=', '!='], true)
                && ($expression->left instanceof ConstantExpression && $expression->left->value === null
                    || $expression->right instanceof ConstantExpression && $expression->right->value === null);
            if (!$nullTest && ($left === null || $right === null)) return null;
        } elseif ($expression instanceof LogicalExpression) {
            $left = $this->collectionPredicate($expression->left, $pair);
            $right = $this->collectionPredicate($expression->right, $pair);
            if ($expression->operator === 'and') {
                if ($left === false || $right === false) return false;
                return $left === null || $right === null ? null : true;
            }
            if ($left === true || $right === true) return true;
            return $left === null || $right === null ? null : false;
        }
        $value = $this->evaluate($expression, $pair);
        return $value === null ? null : (bool) $value;
    }

    private function rightJoinRows(MethodCallExpression $expression): \Generator
    {
        $arguments = $expression->arguments;
        $outer = null;
        foreach ($this->execute($arguments['inner']) as $item) {
            $outer ??= iterator_to_array($this->iterator($this->execute($expression->source)), false);
            $key = $this->evaluate($arguments['innerKey'], $item);
            $matched = false;
            foreach ($outer as $row) {
                if ($this->joinKeysEqual($this->evaluate($arguments['outerKey'], $row), $key)) {
                    $matched = true;
                    yield $this->evaluate($arguments['resultSelector'], ['outer' => $row, 'inner' => $item]);
                }
            }
            if (!$matched) {
                yield $this->evaluate($arguments['resultSelector'], ['outer' => null, 'inner' => $item]);
            }
        }
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

    private function groupJoinRows(iterable $source, MethodCallExpression $expression): \Generator
    {
        $arguments = $expression->arguments;
        $inner = null;
        foreach ($source as $outer) {
            $inner ??= iterator_to_array($this->iterator($this->execute($arguments['inner'])), false);
            $key = $this->evaluate($arguments['outerKey'], $outer);
            $matches = [];
            foreach ($inner as $item) {
                if ($this->joinKeysEqual($key, $this->evaluate($arguments['innerKey'], $item))) {
                    $matches[] = $item;
                }
            }
            yield $this->evaluate($arguments['resultSelector'], [
                'outer' => $outer,
                'inner' => Enumerable::from($matches),
            ]);
        }
    }

    private function zipRows(iterable $source, MethodCallExpression $expression): \Generator
    {
        $second = (function () use ($expression): \Generator {
            yield from $this->execute($expression->arguments['second']);
        })();
        yield from Enumerable::from($source)->zip($second, fn ($outer, $inner) => $this->evaluate(
            $expression->arguments['resultSelector'], ['outer' => $outer, 'inner' => $inner],
        ));
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
        $index = 0;
        foreach ($source as $item) {
            if ((bool) $this->evaluateIndexed($predicate, $item, $index++)) {
                yield $item;
            }
        }
    }

    private function select(iterable $source, ValueExpression $selector): \Generator
    {
        $index = 0;
        foreach ($source as $item) {
            yield $this->evaluateIndexed($selector, $item, $index++);
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
        if ($count <= 0) {
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

    private function evaluateIndexed(ValueExpression $expression, mixed $row, int $index): mixed
    {
        return $this->evaluate($this->bindOuterFields($expression, null, 0, $index, false), $row);
    }

    private function evaluate(ValueExpression $expression, mixed $row): mixed
    {
        return match (true) {
            $expression instanceof \Light\Linq\Expressions\IndexExpression => throw new \LogicException('Index expressions require an indexed operation in the matching scope.'),
            $expression instanceof \Light\Linq\Expressions\OuterFieldExpression => throw new \LogicException('Outer fields require an enclosing AnyExpression.'),
            $expression instanceof \Light\Linq\Expressions\AnyExpression => $this->any($this->execute($this->bindOuterFields($expression->source, $row))),
            $expression instanceof \Light\Linq\Expressions\ContainsExpression => $this->execute(new MethodCallExpression($expression->source, 'contains', ['value' => $this->evaluate($expression->item, $row)])),
            $expression instanceof \Light\Linq\Expressions\NotExpression => ($value = $this->evaluate($expression->operand, $row)) === null ? null : !$value,
            $expression instanceof \Light\Linq\Expressions\GroupKeyExpression => $this->groupKey($expression, $row),
            $expression instanceof \Light\Linq\Expressions\AggregateExpression => $this->aggregate($expression, $row),
            $expression instanceof \Light\Linq\Expressions\CoalesceExpression => $this->evaluate($expression->left, $row)
                ?? $this->evaluate($expression->right, $row),
            $expression instanceof \Light\Linq\Expressions\ConditionalExpression => $this->evaluate($expression->test, $row)
                ? $this->evaluate($expression->ifTrue, $row) : $this->evaluate($expression->ifFalse, $row),
            $expression instanceof \Light\Linq\Expressions\ScalarFunctionExpression => $this->evaluateScalarFunction($expression, $row),
            $expression instanceof ConstantExpression => $expression->value,
            $expression instanceof FieldExpression => $this->readField($row, $expression->path),
            $expression instanceof \Light\Linq\Expressions\StringMatchExpression => $this->evaluateStringMatch($expression, $row),
            $expression instanceof BinaryExpression => $this->evaluateBinary($expression, $row),
            $expression instanceof LogicalExpression => $this->evaluateLogical($expression, $row),
            $expression instanceof ProjectionExpression => $this->evaluateProjection($expression, $row),
            default => throw new \LogicException('Unsupported value expression: '.$expression::class),
        };
    }

    /** Bind one outer row without mutable provider state or leaking scopes through lazy iterators. */
    private function bindOuterFields(Expression $expression, mixed $row, int $depth = 1, ?int $index = null, bool $bindFields = true): Expression
    {
        if ($expression instanceof \Light\Linq\Expressions\IndexExpression) {
            return $index !== null && $expression->depth === $depth ? new ConstantExpression($index) : $expression;
        }
        if ($expression instanceof \Light\Linq\Expressions\OuterFieldExpression) {
            return $bindFields && $expression->depth === $depth
                ? new ConstantExpression($this->readField($row, $expression->path)) : $expression;
        }
        if ($expression instanceof MethodCallExpression) {
            $arguments = $expression->arguments;
            foreach ($arguments as $name => $argument) {
                if ($argument instanceof Expression) { $arguments[$name] = $this->bindOuterFields($argument, $row, $depth, $index, $bindFields); }
            }
            return new MethodCallExpression($this->bindOuterFields($expression->source, $row, $depth, $index, $bindFields), $expression->method, $arguments);
        }
        if ($expression instanceof \Light\Linq\Expressions\ConditionalExpression) {
            return new \Light\Linq\Expressions\ConditionalExpression(
                $this->bindOuterFields($expression->test, $row, $depth, $index, $bindFields),
                $this->bindOuterFields($expression->ifTrue, $row, $depth, $index, $bindFields),
                $this->bindOuterFields($expression->ifFalse, $row, $depth, $index, $bindFields));
        }
        if ($expression instanceof \Light\Linq\Expressions\CoalesceExpression) {
            return new \Light\Linq\Expressions\CoalesceExpression(
                $this->bindOuterFields($expression->left, $row, $depth, $index, $bindFields),
                $this->bindOuterFields($expression->right, $row, $depth, $index, $bindFields));
        }
        if ($expression instanceof BinaryExpression || $expression instanceof LogicalExpression) {
            $class = $expression::class;
            return new $class($this->bindOuterFields($expression->left, $row, $depth, $index, $bindFields), $expression->operator,
                $this->bindOuterFields($expression->right, $row, $depth, $index, $bindFields));
        }
        if ($expression instanceof \Light\Linq\Expressions\AnyExpression) {
            return new \Light\Linq\Expressions\AnyExpression($this->bindOuterFields($expression->source, $row, $depth + 1, $index, $bindFields));
        }
        if ($expression instanceof \Light\Linq\Expressions\ContainsExpression) {
            return new \Light\Linq\Expressions\ContainsExpression($this->bindOuterFields($expression->item, $row, $depth, $index, $bindFields),
                $bindFields ? $this->bindOuterFields($expression->source, $row, $depth, $index, $bindFields) : $expression->source);
        }
        if ($expression instanceof \Light\Linq\Expressions\NotExpression) {
            return new \Light\Linq\Expressions\NotExpression($this->bindOuterFields($expression->operand, $row, $depth, $index, $bindFields));
        }
        if ($expression instanceof \Light\Linq\Expressions\StringMatchExpression) {
            return new \Light\Linq\Expressions\StringMatchExpression(
                $this->bindOuterFields($expression->subject, $row, $depth, $index, $bindFields), $expression->operation,
                $this->bindOuterFields($expression->needle, $row, $depth, $index, $bindFields));
        }
        if ($expression instanceof \Light\Linq\Expressions\ScalarFunctionExpression) {
            return new \Light\Linq\Expressions\ScalarFunctionExpression($expression->function,
                array_map(fn ($argument) => $this->bindOuterFields($argument, $row, $depth, $index, $bindFields), $expression->arguments));
        }
        if ($expression instanceof ProjectionExpression) {
            return new ProjectionExpression(array_map(fn ($member) => $this->bindOuterFields($member, $row, $depth, $index, $bindFields), $expression->members));
        }
        if ($expression instanceof \Light\Linq\Expressions\AggregateExpression && $expression->operand !== null) {
            return new \Light\Linq\Expressions\AggregateExpression($expression->function,
                $this->bindOuterFields($expression->operand, $row, $depth, $index, $bindFields));
        }
        return $expression;
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

    private function evaluateScalarFunction(\Light\Linq\Expressions\ScalarFunctionExpression $expression, mixed $row): mixed
    {
        $value = $this->evaluate($expression->arguments[0], $row);
        if ($value === null) { return null; }
        return match ($expression->function) {
            'strtolower' => strtolower($value),
            'strtoupper' => strtoupper($value),
            'trim' => trim($value),
            'strlen' => strlen($value),
            'abs' => abs($value),
            'round' => round($value, $expression->arguments[1]->value ?? 0, PHP_ROUND_HALF_UP),
        };
    }

    private function evaluateStringMatch(\Light\Linq\Expressions\StringMatchExpression $expression, mixed $row): ?bool
    {
        $subject = $this->evaluate($expression->subject, $row);
        $needle = $this->evaluate($expression->needle, $row);
        if ($subject === null || $needle === null) { return null; }
        return match ($expression->operation) {
            'startsWith' => str_starts_with($subject, $needle),
            'endsWith' => str_ends_with($subject, $needle),
            'contains' => str_contains($subject, $needle),
        };
    }

    private function evaluateBinary(BinaryExpression $expression, mixed $row): mixed
    {
        $left = $this->evaluate($expression->left, $row);
        $right = $this->evaluate($expression->right, $row);
        if (in_array($expression->operator, ['+', '-', '*', '/', '%'], true) && ($left === null || $right === null)) {
            return null;
        }
        return match ($expression->operator) {
            '+' => $left + $right,
            '-' => $left - $right,
            '*' => $left * $right,
            '/' => $left / $right,
            '%' => $left % $right,
            '=' => $left === $right,
            '!=' => $left !== $right,
            '>' => $left > $right,
            '>=' => $left >= $right,
            '<' => $left < $right,
            '<=' => $left <= $right,
        };
    }

    private function evaluateLogical(LogicalExpression $expression, mixed $row): ?bool
    {
        $left = $this->evaluate($expression->left, $row);
        if ($expression->operator === 'and' && $left !== null && !$left) { return false; }
        if ($expression->operator === 'or' && $left !== null && $left) { return true; }
        $right = $this->evaluate($expression->right, $row);
        if ($expression->operator === 'and' && $right !== null && !$right) { return false; }
        if ($expression->operator === 'or' && $right !== null && $right) { return true; }
        return $left === null || $right === null ? null : (bool) $right;
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
