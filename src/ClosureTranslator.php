<?php

declare(strict_types=1);

namespace Light\Linq;

use Light\Linq\Expressions\{ValueExpression, FieldExpression, ConstantExpression, BinaryExpression, LogicalExpression, ProjectionExpression, ContainsExpression, NotExpression, AnyExpression, OuterFieldExpression, MethodCallExpression};
use PhpParser\{Node, NodeFinder, ParserFactory};
use PhpParser\Node\Expr as Ast;

/** Translates a deliberately limited PHP query language; never invokes the closure. */
final class ClosureTranslator
{
    /** Parsed source only; captured values are read afresh for every translation. */
    private static array $parsedFiles = [];
    public static function translate(\Closure $closure, ?IQueryProvider $provider = null): ValueExpression
    {
        $reflection = new \ReflectionFunction($closure);
        $file = $reflection->getFileName();
        if ($file === false || !is_file($file) || !is_readable($file)) {
            throw new ClosureTranslationException('Query closures require a readable PHP source file; eval and internal closures are unsupported.');
        }
        $source = file_get_contents($file);
        if ($source === false) { throw new ClosureTranslationException('Cannot read query closure source.'); }
        try {
            $cacheKey = $file.':'.hash('sha256', $source);
            if (!array_key_exists($cacheKey, self::$parsedFiles)) {
                if (count(self::$parsedFiles) >= 32) { array_shift(self::$parsedFiles); }
                self::$parsedFiles[$cacheKey] = (new ParserFactory())->createForNewestSupportedVersion()->parse($source) ?? [];
            }
            $tree = self::$parsedFiles[$cacheKey];
        } catch (\PhpParser\Error $error) {
            throw new ClosureTranslationException('Cannot parse query closure source: '.$error->getMessage(), 0, $error);
        }
        $parameterNames = array_map(static fn ($parameter) => $parameter->getName(), $reflection->getParameters());
        $matches = (new NodeFinder())->find($tree ?? [], static fn (Node $node) =>
            ($node instanceof Ast\ArrowFunction || $node instanceof Ast\Closure)
            && array_map(static fn ($parameter) => $parameter->var->name, $node->params) === $parameterNames
            && $node->getStartLine() === $reflection->getStartLine()
            && $node->getEndLine() === $reflection->getEndLine());
        if (count($matches) !== 1) {
            throw new ClosureTranslationException('Cannot uniquely locate query closure; put closures on separate lines.');
        }
        $node = $matches[0];
        if (count($node->params) !== 1 || $node->byRef || $node->params[0]->byRef
            || $node->params[0]->variadic || !is_string($node->params[0]->var->name)) {
            throw new ClosureTranslationException('Query closures require exactly one ordinary row parameter.');
        }
        if ($node instanceof Ast\ArrowFunction) {
            $body = $node->expr;
        } elseif (count($node->stmts) === 1 && $node->stmts[0] instanceof Node\Stmt\Return_ && $node->stmts[0]->expr !== null) {
            $body = $node->stmts[0]->expr;
        } else {
            throw new ClosureTranslationException('Query closures must contain a single return expression.');
        }
        return self::expression($body, $node->params[0]->var->name, $reflection->getStaticVariables(), $provider);
    }

    private static function expression(Node $node, string $row, array $captures, ?IQueryProvider $provider, array $scopes = []): ValueExpression
    {
        if ($node instanceof Ast\PropertyFetch && $node->name instanceof Node\Identifier) {
            $path = [$node->name->toString()];
            $base = $node->var;
            while ($base instanceof Ast\PropertyFetch && $base->name instanceof Node\Identifier) {
                array_unshift($path, $base->name->toString());
                $base = $base->var;
            }
            if ($base instanceof Ast\Variable && is_string($base->name)) {
                if ($base->name === $row) { return new FieldExpression(implode('.', $path)); }
                $depth = array_search($base->name, $scopes, true);
                if ($depth !== false) { return new OuterFieldExpression(implode('.', $path), $depth + 1); }
            }
        }
        if ($node instanceof Ast\Variable && is_string($node->name) && $node->name !== $row && array_key_exists($node->name, $captures)) {
            $value = $captures[$node->name];
            if ($value === null || is_scalar($value)) { return new ConstantExpression($value); }
            throw new ClosureTranslationException('Captured operands must be scalar or null; query objects are supported only as contains()/any() receivers.');
        }
        if ($node instanceof Node\Scalar\String_ || $node instanceof Node\Scalar\Int_ || $node instanceof Node\Scalar\Float_) {
            return new ConstantExpression($node->value);
        }
        if ($node instanceof Ast\ConstFetch) {
            $name = strtolower($node->name->toString());
            if (in_array($name, ['true', 'false', 'null'], true)) {
                return new ConstantExpression(match ($name) { 'true'=>true, 'false'=>false, 'null'=>null });
            }
        }
        if ($node instanceof Ast\UnaryMinus || $node instanceof Ast\UnaryPlus) {
            $operand = self::expression($node->expr, $row, $captures, $provider, $scopes);
            if ($operand instanceof ConstantExpression && (is_int($operand->value) || is_float($operand->value))) {
                return new ConstantExpression($node instanceof Ast\UnaryMinus ? -$operand->value : +$operand->value);
            }
        }
        if ($node instanceof Ast\BooleanNot) {
            return new NotExpression(self::expression($node->expr, $row, $captures, $provider, $scopes));
        }
        if ($node instanceof Ast\BinaryOp\Coalesce) {
            return new \Light\Linq\Expressions\CoalesceExpression(
                self::expression($node->left, $row, $captures, $provider, $scopes),
                self::expression($node->right, $row, $captures, $provider, $scopes));
        }
        if ($node instanceof Ast\Ternary) {
            if ($node->if === null) {
                throw new ClosureTranslationException('Short ternary ?: is unsupported; use an explicit condition ? value : fallback.');
            }
            return new \Light\Linq\Expressions\ConditionalExpression(
                self::expression($node->cond, $row, $captures, $provider, $scopes),
                self::expression($node->if, $row, $captures, $provider, $scopes),
                self::expression($node->else, $row, $captures, $provider, $scopes));
        }
        if ($node instanceof Ast\BinaryOp) {
            $operator = match (true) {
                $node instanceof Ast\BinaryOp\BooleanAnd, $node instanceof Ast\BinaryOp\LogicalAnd => 'and',
                $node instanceof Ast\BinaryOp\BooleanOr, $node instanceof Ast\BinaryOp\LogicalOr => 'or',
                $node instanceof Ast\BinaryOp\Plus => '+',
                $node instanceof Ast\BinaryOp\Minus => '-',
                $node instanceof Ast\BinaryOp\Mul => '*',
                $node instanceof Ast\BinaryOp\Div => '/',
                $node instanceof Ast\BinaryOp\Mod => '%',
                $node instanceof Ast\BinaryOp\Equal => '=',
                $node instanceof Ast\BinaryOp\NotEqual => '!=',
                $node instanceof Ast\BinaryOp\Greater => '>',
                $node instanceof Ast\BinaryOp\GreaterOrEqual => '>=',
                $node instanceof Ast\BinaryOp\Smaller => '<',
                $node instanceof Ast\BinaryOp\SmallerOrEqual => '<=',
                default => throw new ClosureTranslationException('Unsupported query operator: '.$node->getType()),
            };
            $left = self::expression($node->left, $row, $captures, $provider, $scopes);
            $right = self::expression($node->right, $row, $captures, $provider, $scopes);
            return in_array($operator, ['and', 'or'], true)
                ? new LogicalExpression($left, $operator, $right) : new BinaryExpression($left, $operator, $right);
        }
        if ($node instanceof Ast\MethodCall && $node->name instanceof Node\Identifier
            && $node->name->toString() === 'any' && $node->var instanceof Ast\Variable
            && is_string($node->var->name) && $node->var->name !== $row
            && !in_array($node->var->name, $scopes, true)) {
            $query = $captures[$node->var->name] ?? null;
            if (!$query instanceof IQueryable || count($node->args) > 1) {
                throw new ClosureTranslationException('any() requires a captured IQueryable and zero or one predicate closure.');
            }
            if ($provider !== null && $query->provider() !== $provider) {
                throw new ClosureTranslationException('any() subqueries must use the same query provider.');
            }
            $subquery = $query->expression();
            if ($node->args !== []) {
                $arg = $node->args[0];
                if (!$arg instanceof Node\Arg || $arg->unpack || $arg->name !== null
                    || (!$arg->value instanceof Ast\ArrowFunction && !$arg->value instanceof Ast\Closure)) {
                    throw new ClosureTranslationException('any() predicates must be inline query closures.');
                }
                $closure = $arg->value;
                if (count($closure->params) !== 1 || $closure->byRef || $closure->params[0]->byRef
                    || $closure->params[0]->variadic || !is_string($closure->params[0]->var->name)) {
                    throw new ClosureTranslationException('any() predicates require exactly one ordinary row parameter.');
                }
                $innerCaptures = $captures;
                $innerScopes = [$row, ...$scopes];
                if ($closure instanceof Ast\ArrowFunction) {
                    $body = $closure->expr;
                } else {
                    if (count($closure->stmts) !== 1 || !$closure->stmts[0] instanceof Node\Stmt\Return_ || $closure->stmts[0]->expr === null) {
                        throw new ClosureTranslationException('any() predicates must contain a single return expression.');
                    }
                    $body = $closure->stmts[0]->expr;
                    $uses = array_map(static fn ($use) => $use->var->name, $closure->uses);
                    $innerCaptures = array_intersect_key($captures, array_flip($uses));
                    // Preserve scope distances even when an explicit use list hides a row.
                    $innerScopes = array_map(static fn ($name) => in_array($name, $uses, true) ? $name : null, $innerScopes);
                }
                $predicate = self::expression($body, $closure->params[0]->var->name, $innerCaptures, $provider, $innerScopes);
                $subquery = new MethodCallExpression($subquery, 'where', ['predicate' => $predicate]);
            }
            return new AnyExpression($subquery);
        }
        if ($node instanceof Ast\MethodCall && $node->name instanceof Node\Identifier
            && $node->name->toString() === 'contains' && $node->var instanceof Ast\Variable
            && is_string($node->var->name) && $node->var->name !== $row && !in_array($node->var->name, $scopes, true)) {
            $query = $captures[$node->var->name] ?? null;
            if (!$query instanceof IQueryable || count($node->args) !== 1
                || !$node->args[0] instanceof Node\Arg || $node->args[0]->unpack || $node->args[0]->name !== null) {
                throw new ClosureTranslationException('contains() requires a captured IQueryable and one positional value argument.');
            }
            if ($provider !== null && $query->provider() !== $provider) {
                throw new ClosureTranslationException('contains() subqueries must use the same query provider.');
            }
            return new ContainsExpression(self::expression($node->args[0]->value, $row, $captures, $provider, $scopes), $query->expression());
        }
        if ($node instanceof Ast\FuncCall && $node->name instanceof Node\Name
            && !$node->name->isRelative()
            && in_array(strtolower($node->name->toString()), ['str_starts_with', 'str_ends_with', 'str_contains'], true)) {
            if (count($node->args) !== 2) {
                throw new ClosureTranslationException('String matching requires two positional arguments.');
            }
            foreach ($node->args as $argument) {
                if (!$argument instanceof Node\Arg || $argument->unpack || $argument->name !== null) {
                    throw new ClosureTranslationException('String matching requires two positional arguments.');
                }
            }
            $operation = match (strtolower($node->name->toString())) {
                'str_starts_with' => 'startsWith',
                'str_ends_with' => 'endsWith',
                'str_contains' => 'contains',
            };
            return new \Light\Linq\Expressions\StringMatchExpression(
                self::expression($node->args[0]->value, $row, $captures, $provider, $scopes),
                $operation,
                self::expression($node->args[1]->value, $row, $captures, $provider, $scopes),
            );
        }
        if ($node instanceof Ast\Array_) {
            $members = [];
            foreach ($node->items as $item) {
                if ($item === null || $item->unpack || $item->byRef || !$item->key instanceof Node\Scalar\String_) {
                    throw new ClosureTranslationException('Query projections require explicit string keys.');
                }
                $name = $item->key->value;
                if (array_key_exists($name, $members)) { throw new ClosureTranslationException('Duplicate projection alias: '.$name); }
                $members[$name] = self::expression($item->value, $row, $captures, $provider, $scopes);
            }
            if ($members === []) { throw new ClosureTranslationException('Query projections must not be empty.'); }
            return new ProjectionExpression($members);
        }
        throw new ClosureTranslationException('Cannot translate PHP node to a query expression: '.$node->getType());
    }
}
