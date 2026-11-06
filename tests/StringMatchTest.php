<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\{ClosureTranslator, ClosureTranslationException, InMemoryQueryProvider, Queryable};
use Light\Linq\Expressions\StringMatchExpression;
use PHPUnit\Framework\TestCase;

final class StringMatchTest extends TestCase
{
    private function names(): Queryable
    {
        return Queryable::from(new InMemoryQueryProvider(['names' => [
            (object) ['id' => 1, 'name' => 'alpha', 'needle' => 'al'],
            (object) ['id' => 2, 'name' => 'beta', 'needle' => 'et'],
            (object) ['id' => 3, 'name' => 'a%_![z', 'needle' => '%_!['],
            (object) ['id' => 4, 'name' => '', 'needle' => 'x'],
        ]]), 'names');
    }

    public function testLiteralMatchingAndNegation(): void
    {
        self::assertSame([1, 3], $this->names()->where(fn($c) => str_starts_with($c->name, 'a'))
            ->select(fn($c) => $c->id)->toArray());
        self::assertSame([1, 2], $this->names()->where(fn($c) => str_ends_with($c->name, 'a'))
            ->select(fn($c) => $c->id)->toArray());
        self::assertSame([3], $this->names()->where(fn($c) => str_contains($c->name, '%_!['))
            ->select(fn($c) => $c->id)->toArray());
        self::assertSame([2, 4], $this->names()->where(fn($c) => !str_starts_with($c->name, 'a'))
            ->select(fn($c) => $c->id)->toArray());
    }

    public function testCaptureDynamicNeedleEmptyNeedleAndProjection(): void
    {
        $prefix = 'al';
        $predicate = ClosureTranslator::translate(fn($c) => \str_starts_with($c->name, $prefix));
        self::assertInstanceOf(StringMatchExpression::class, $predicate);
        self::assertSame('startsWith', $predicate->operation);
        self::assertSame([1], $this->names()->where($predicate)
            ->select(fn($c) => $c->id)->toArray());
        self::assertSame([1, 2, 3], $this->names()->where(fn($c) => str_contains($c->name, $c->needle))
            ->select(fn($c) => $c->id)->toArray());
        self::assertSame([true, true, true, true], $this->names()->select(fn($c) => str_ends_with($c->name, ''))->toArray());
    }

    public function testStringMatchBindsCorrelatedOuterFields(): void
    {
        $names = $this->names();
        self::assertSame([1, 2, 3], $names->where(fn($outer) => $names->any(
            fn($inner) => str_contains($inner->name, $outer->needle)
        ))
            ->select(fn($c) => $c->id)->toArray());
    }

    public function testNullMatchesRemainUnknownUnderNegationAndLogicalOperators(): void
    {
        $query = Queryable::from(new InMemoryQueryProvider(['names' => [(object) ['name' => null]]]), 'names');
        self::assertSame([null], $query->select(fn($c) => str_starts_with($c->name, 'a'))->toArray());
        self::assertSame([], $query->where(fn($c) => !str_starts_with($c->name, 'a'))->toArray());
        self::assertSame([], $query->where(fn($c) => !(str_starts_with($c->name, 'a') && true))->toArray());
        self::assertSame([true], $query->select(fn($c) => !(str_starts_with($c->name, 'a') && false))->toArray());
    }

    public function testNamedArgumentsAreRejected(): void
    {
        $this->expectException(ClosureTranslationException::class);
        ClosureTranslator::translate(fn($c) => str_starts_with(haystack: $c->name, needle: 'a'));
    }
}
