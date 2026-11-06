<?php

declare(strict_types=1);

namespace Light\Linq\Expressions;

/** A closed set of provider-independent PHP scalar operations, not arbitrary calls. */
final readonly class ScalarFunctionExpression implements ValueExpression
{
    /** @var list<ValueExpression> */
    public array $arguments;

    /** @param list<ValueExpression> $arguments */
    public function __construct(public string $function, array $arguments)
    {
        if (!in_array($function, ['strtolower', 'strtoupper', 'trim', 'strlen', 'abs', 'round'], true)) {
            throw new \InvalidArgumentException('Unsupported scalar function: '.$function);
        }
        if (count($arguments) !== 1 && !($function === 'round' && count($arguments) === 2)) {
            throw new \InvalidArgumentException($function.' requires one argument (round accepts an optional precision).');
        }
        foreach ($arguments as $argument) {
            if (!$argument instanceof ValueExpression) {
                throw new \InvalidArgumentException('Scalar function arguments must be value expressions.');
            }
        }
        $arguments = array_values($arguments);
        if ($function === 'round' && isset($arguments[1])
            && (!$arguments[1] instanceof ConstantExpression || !is_int($arguments[1]->value))) {
            throw new \InvalidArgumentException('round precision must be a constant integer.');
        }
        $this->arguments = $arguments;
    }
}
