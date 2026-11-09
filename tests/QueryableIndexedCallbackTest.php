<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\ClosureTranslationException;
use Light\Linq\Enumerable;
use Light\Linq\Expr;
use Light\Linq\Expressions\IndexExpression;
use Light\Linq\InMemoryQueryProvider;
use Light\Linq\Queryable;
use PHPUnit\Framework\TestCase;

final class QueryableIndexedCallbackTest extends TestCase
{
    private function query(iterable $rows): Queryable
    {
        return Queryable::from(new InMemoryQueryProvider(['rows' => $rows]), 'rows');
    }

    public function testWhereAndSelectRestartIndexesAfterFilteringAndReplay(): void
    {
        $query = $this->query(['a' => ['name' => 'Ada'], 99 => ['name' => 'Bob'], 'c' => ['name' => 'Cara']]);
        $indexed = $query->where(fn ($row, $i) => $i % 2 == 0)
            ->select(fn ($row, $i) => ['name' => $row->name, 'position' => $i]);
        $expected = [['name' => 'Ada', 'position' => 0], ['name' => 'Cara', 'position' => 1]];
        self::assertSame($expected, $indexed->toArray());
        self::assertSame($expected, $indexed->toArray());
        self::assertSame([0, 1, 2], $query->select(fn ($row, $i) => $i)->toArray());
        self::assertSame([0, 1], $query->skip(1)->select(fn ($row, $i) => $i)->toArray());
    }

    public function testExplicitIndexExpressionAndAnonymousClosureCapture(): void
    {
        $query = $this->query([['n' => 10], ['n' => 20], ['n' => 30]]);
        self::assertSame([0, 1, 2], $query->select(Expr::index())->toArray());
        self::assertSame([['n' => 20]], $query->where(Expr::eq(Expr::index(), 1))->toArray());
        $offset = 5;
        $project = function ($row, $position) use ($offset) { return ['n' => $row->n, 'index' => $position + $offset]; };
        $translated = $query->select($project);
        self::assertSame([['n' => 10, 'index' => 5], ['n' => 20, 'index' => 6], ['n' => 30, 'index' => 7]], $translated->toArray());
        self::assertInstanceOf(IndexExpression::class, $query->select(fn ($row, $i) => $i)->expression()->arguments['selector']);
    }

    public function testIndexPropagatesThroughNestedArithmeticConditionAndFunctions(): void
    {
        $query = $this->query([['n' => 10], ['n' => 20], ['n' => 30]]);
        self::assertSame([['v' => 10, 'fallback' => 0], ['v' => 1, 'fallback' => 1], ['v' => 2, 'fallback' => 2]],
            $query->select(fn ($row, $i) => ['v' => !($i > 0) ? $row->n : abs($i), 'fallback' => $i ?? 99])->toArray());
        self::assertSame([1.0, 1.5, 2.0], $query->select(fn ($row, $i) => round(($i + 2) / 2, 1))->toArray());
    }

    public function testWhileMethodsStopPredicatesAndReadingAtCorrectPositions(): void
    {
        $rows = [['n' => 1], ['n' => 2], ['n' => 5], ['missing' => 1]];
        self::assertSame([['n' => 5], ['missing' => 1]], $this->query($rows)
            ->skipWhile(fn ($row, $i) => $i < 2 && $row->n < 3)->toArray());
        self::assertSame([['n' => 1], ['n' => 2]], $this->query($rows)
            ->takeWhile(fn ($row, $i) => $i < 2 && $row->n < 3)->toArray());
        self::assertSame([0, 1], $this->query($rows)->skipWhile(fn ($row, $i) => $i < 2)
            ->select(fn ($row, $i) => $i)->toArray());
        self::assertSame([], $this->query([])->takeWhile(fn ($row, $i) => $i < 2)->toArray());
    }

    public function testSelectManyCollectionSelectorUsesOuterIndexIncludingEmptyCollections(): void
    {
        $query = $this->query([['items' => ['a', 'b']], ['items' => []], ['items' => ['c']]]);
        self::assertSame(['a', 'b', 'c'], $query->selectMany(fn ($row) => $row->items)->toArray());
        $flattened = $query->selectMany(fn ($row, $i) => $row->items,
            Expr::projection(['value' => Expr::field('inner'), 'outerIndex' => Expr::index()]));
        self::assertSame([['value' => 'a', 'outerIndex' => 0], ['value' => 'b', 'outerIndex' => 0], ['value' => 'c', 'outerIndex' => 2]], $flattened->toArray());
        self::assertSame([0, 1, 2], $flattened->select(fn ($row, $i) => $i)->toArray());
        self::assertSame([], $this->query([])->selectMany(fn ($row, $i) => $row->items)->toArray());
    }

    public function testSelectManyCorrelatedCollectionsAndExistingQuerySourceCompose(): void
    {
        $query = $this->query([['a' => [1, 2], 'b' => [3]], ['a' => [], 'b' => [4]]]);
        self::assertSame([1, 2, 4], $query->selectMany(fn ($row, $i) => $i == 0 ? $row->a : $row->b)->toArray());
        $provider = new InMemoryQueryProvider(['outer' => [['id' => 1], ['id' => 2]], 'inner' => [['n' => 10], ['n' => 20]]]);
        $outer = Queryable::from($provider, 'outer');
        $inner = Queryable::from($provider, 'inner');
        self::assertSame([0, 0, 1, 1], $outer->selectMany($inner, Expr::index())->toArray());
        self::assertSame([10, 20], $outer->selectMany($inner, Expr::field('inner.n'), Expr::eq(Expr::index(), 1))->toArray());
    }

    public function testSelectManyRejectsNonIterableOnlyWhenEnumerated(): void
    {
        $query = $this->query([['items' => null]])->selectMany(fn ($row, $i) => $row->items);
        $this->expectException(\UnexpectedValueException::class);
        $query->toArray();
    }

    public function testInterleavedIteratorsHaveIndependentIndexesAndDoNotReadAhead(): void
    {
        $reads = 0;
        $source = Enumerable::defer(static function () use (&$reads): iterable {
            foreach ([10, 20, 30] as $n) { ++$reads; yield ['n' => $n]; }
        });
        $query = $this->query($source)->select(fn ($row, $i) => $i);
        self::assertSame(0, $reads);
        $one = $query->getIterator();
        $two = $query->getIterator();
        self::assertSame(0, $one->current());
        $one->next();
        self::assertSame(1, $one->current());
        self::assertSame(0, $two->current());
        self::assertSame(3, $reads);
        $two->next();
        self::assertSame(1, $two->current());
        $one->next();
        self::assertSame(2, $one->current());
        self::assertSame([0], $query->take(1)->toArray());
        self::assertSame(6, $reads);
    }

    public function testCollectionGeneratorsStreamWithoutReadingAhead(): void
    {
        $items = (static function (): iterable { yield 1; throw new \RuntimeException('Read ahead'); })();
        self::assertSame([1], $this->query([['items' => $items]])->selectMany(fn ($row, $i) => $row->items)->take(1)->toArray());
    }

    public function testOuterIndexInAnyAndIndependentCapturedIndexedQueries(): void
    {
        $provider = new InMemoryQueryProvider(['rows' => [['n' => 10], ['n' => 20], ['n' => 30]], 'keys' => [['n' => 0], ['n' => 2]]]);
        $rows = Queryable::from($provider, 'rows');
        $keys = Queryable::from($provider, 'keys');
        self::assertSame([10, 30], $rows->where(fn ($row, $i) => $keys->any(fn ($key) => $key->n == $i))->select(Expr::field('n'))->toArray());
        $indexedKeys = $keys->select(fn ($key, $i) => $i);
        self::assertSame([10, 20], $rows->where(fn ($row, $i) => $indexedKeys->contains($i))->select(Expr::field('n'))->toArray());
        self::assertSame(3, $rows->where(fn ($row, $i) => $indexedKeys->any())->count());
    }

    public function testUnsupportedParameterShapesAndNonIndexedOperatorsAreRejected(): void
    {
        $query = $this->query([]);
        $invalid = [
            fn ($row, $i, $extra) => $i,
            fn ($row, &$i) => $i,
            fn ($row, ...$i) => 1,
        ];
        foreach ($invalid as $callback) {
            try { $query->select($callback); self::fail('Expected invalid callback'); }
            catch (ClosureTranslationException) { self::assertTrue(true); }
        }
        $this->expectException(ClosureTranslationException::class);
        $query->countBy(fn ($row, $i) => $i);
    }

    public function testNestedAnyPreservesOuterIndexDepthAndExplicitUseVisibility(): void
    {
        $provider = new InMemoryQueryProvider([
            'rows' => [['n' => 10], ['n' => 20], ['n' => 30]],
            'keys' => [['n' => 0], ['n' => 2]], 'other' => [['n' => 2]],
        ]);
        $rows = Queryable::from($provider, 'rows');
        $keys = Queryable::from($provider, 'keys');
        $other = Queryable::from($provider, 'other');
        $nested = $rows->where(fn ($row, $i) => $keys->any(
            fn ($key) => $key->n == $i && $other->any(fn ($o) => $o->n == $i)
        ));
        self::assertSame([30], $nested->select(Expr::field('n'))->toArray());
        $explicit = $rows->where(fn ($row, $i) => $keys->any(function ($key) use ($i) { return $key->n == $i; }));
        self::assertSame([10, 30], $explicit->select(Expr::field('n'))->toArray());
        self::assertSame(3, $rows->where(fn ($row, $i) => $keys->any(fn ($i) => $i->n == 0))->count());
        $this->expectException(ClosureTranslationException::class);
        $rows->where(fn ($row, $i) => $keys->any(function ($key) { return $key->n == $i; }));
    }

    public function testUnboundIndexFailsExplicitlyAndIndexDepthIsValidated(): void
    {
        try { new IndexExpression(-1); self::fail('Expected invalid scope'); }
        catch (\InvalidArgumentException) { self::assertTrue(true); }
        $this->expectException(\LogicException::class);
        $this->query([['n' => 1], ['n' => 2]])->orderBy(Expr::index())->toArray();
    }
}
