<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\Enumerable;
use Light\Linq\Expr;
use Light\Linq\Expression\MethodCallExpression;
use Light\Linq\IEnumerable;
use Light\Linq\InMemoryQueryProvider;
use Light\Linq\Queryable;
use PHPUnit\Framework\TestCase;

final class QueryableSequenceOperatorsTest extends TestCase
{
    private function queries(iterable $outer, iterable $inner): array
    {
        $provider = new InMemoryQueryProvider(['outer' => $outer, 'inner' => $inner]);
        return [Queryable::from($provider, 'outer'), Queryable::from($provider, 'inner')];
    }

    public function testGroupJoinKeepsEveryOuterRowAndDuplicateInnerMatches(): void
    {
        [$outer, $inner] = $this->queries(
            [['id' => 1], ['id' => 2], ['id' => 1], ['id' => null]],
            [['id' => 1, 'name' => 'Ada'], ['id' => 1, 'name' => 'Alice'], ['id' => null, 'name' => 'Null']],
        );
        $query = $outer->groupJoin($inner, Expr::field('id'), Expr::field('id'),
            Expr::projection(['id' => 'outer.id', 'matches' => 'inner']));
        $rows = $query->toArray();
        self::assertSame([1, 2, 1, null], array_column($rows, 'id'));
        foreach ($rows as $row) self::assertInstanceOf(IEnumerable::class, $row['matches']);
        self::assertSame(['Ada', 'Alice'], $rows[0]['matches']->select(fn ($row) => $row['name'])->toArray());
        self::assertSame([], $rows[1]['matches']->toArray());
        self::assertSame($rows[0]['matches']->toArray(), $rows[2]['matches']->toArray());
        self::assertSame([], $rows[3]['matches']->toArray());
        self::assertSame(4, $query->count());
        self::assertSame([2], $query->where(Expr::eq(Expr::field('id'), 2))->select(Expr::field('id'))->toArray());
        self::assertSame(['Ada', 'Alice'], $rows[0]['matches']->select(fn ($row) => $row['name'])->toArray());
    }

    public function testGroupJoinSupportsFilteredPagedAndEmptySources(): void
    {
        [$outer, $inner] = $this->queries([['id' => 1], ['id' => 2]], [['id' => 1], ['id' => 2]]);
        $query = $outer->orderByDescending(Expr::field('id'))->take(1)->groupJoin(
            $inner->where(Expr::eq(Expr::field('id'), 1)), Expr::field('id'), Expr::field('id'),
            Expr::projection(['id' => 'outer.id', 'matches' => 'inner']),
        );
        self::assertSame(2, $query->first()['id']);
        self::assertCount(0, $query->first()['matches']);
        $groups = $outer->groupJoin($inner->take(0), Expr::field('id'), Expr::field('id'), Expr::field('inner'))->toArray();
        self::assertSame([[], []], array_map(fn ($group) => $group->toArray(), $groups));
        self::assertSame([], $outer->take(0)->groupJoin($inner, Expr::field('id'), Expr::field('id'), Expr::field('inner'))->toArray());
    }

    public function testGroupJoinUsesExistingRelationalKeyRules(): void
    {
        [$outer, $inner] = $this->queries(
            [['id' => 1, 'region' => 'HK'], ['id' => '1', 'region' => 'HK'], ['id' => 1, 'region' => null]],
            [['id' => 1, 'region' => 'HK'], ['id' => 1, 'region' => null]],
        );
        $key = Expr::fields('id', 'region');
        $groups = $outer->groupJoin($inner, $key, $key, Expr::field('inner'))->toArray();
        self::assertSame([1, 0, 0], array_map(fn ($group) => $group->count(), $groups));
    }

    public function testGroupJoinIsDeferredBuffersInnerOncePerExecutionAndStreamsOuter(): void
    {
        $outerReads = $innerReads = 0;
        [$outer, $inner] = $this->queries(
            Enumerable::defer(static function () use (&$outerReads): iterable {
                foreach ([1, 2] as $id) { ++$outerReads; yield ['id' => $id]; }
            }),
            Enumerable::defer(static function () use (&$innerReads): iterable {
                foreach ([1, 2] as $id) { ++$innerReads; yield ['id' => $id]; }
            }),
        );
        $query = $outer->groupJoin($inner, Expr::field('id'), Expr::field('id'), Expr::field('inner'));
        self::assertSame([0, 0], [$outerReads, $innerReads]);
        self::assertCount(1, $query->first());
        self::assertSame([1, 2], [$outerReads, $innerReads]);
        self::assertSame(2, $query->count());
        self::assertSame([3, 4], [$outerReads, $innerReads]);
    }

    public function testEmptyOuterDoesNotReadInnerForGroupJoinOrZip(): void
    {
        [$outer, $inner] = $this->queries([], Enumerable::defer(static function (): iterable {
            throw new \RuntimeException('Unexpected inner read'); yield;
        }));
        self::assertSame([], $outer->groupJoin($inner, Expr::field('id'), Expr::field('id'), Expr::field('inner'))->toArray());
        self::assertSame([], $outer->zip($inner, Expr::field('inner'))->toArray());
    }

    public function testZipPairsPositionsToShortestLengthAndComposes(): void
    {
        [$outer, $inner] = $this->queries(
            ['a' => ['id' => 3], 'b' => ['id' => 1], 'c' => ['id' => 2]],
            [9 => ['name' => 'Ada'], 8 => ['name' => 'Bob']],
        );
        $query = $outer->orderBy(Expr::field('id'))->zip($inner,
            Expr::projection(['id' => 'outer.id', 'name' => 'inner.name']));
        self::assertSame([['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => 'Bob']], $query->toArray());
        self::assertSame(2, $query->count());
        self::assertSame(['Bob'], $query->where(Expr::eq(Expr::field('id'), 2))->select(Expr::field('name'))->toArray());
        self::assertSame([], $outer->zip($inner->take(0), Expr::field('inner'))->toArray());
        self::assertSame([['id' => 3]], $outer->take(1)->zip($inner, Expr::field('outer'))->toArray());
    }

    public function testZipPreservesScalarNullAndFalseValues(): void
    {
        [$outer, $inner] = $this->queries([null, false, 1], [false, null]);
        self::assertSame([
            ['first' => null, 'second' => false], ['first' => false, 'second' => null],
        ], $outer->zip($inner, Expr::projection(['first' => 'outer', 'second' => 'inner']))->toArray());
    }

    public function testZipDefersReadsAndDoesNotReadAheadWhenTakingFirstPair(): void
    {
        $reads = 0;
        $source = Enumerable::defer(static function () use (&$reads): iterable {
            ++$reads; yield 1;
            throw new \RuntimeException('Read too far');
        });
        [$outer, $inner] = $this->queries($source, $source);
        $query = $outer->zip($inner, Expr::field('outer'))->take(1);
        self::assertSame(0, $reads);
        self::assertSame([1], $query->toArray());
        self::assertSame(2, $reads);
        self::assertSame([1], $query->toArray());
        self::assertSame(4, $reads);
    }

    public function testZipDoesNotAdvanceSecondAfterFirstEnds(): void
    {
        [$outer, $inner] = $this->queries([1], Enumerable::defer(static function (): iterable {
            yield 10;
            throw new \RuntimeException('Unexpected second read');
        }));
        self::assertSame([10], $outer->zip($inner, Expr::field('inner'))->toArray());
    }

    public function testChunkPreservesOrderAndPartialChunksAndComposes(): void
    {
        [$query] = $this->queries(['a' => null, 'b' => false, 'c' => 3, 'd' => 4, 'e' => 5], []);
        self::assertSame([[null, false], [3, 4], [5]], $query->chunk(2)->toArray());
        self::assertSame([[3, 4]], $query->chunk(2)->skip(1)->take(1)->toArray());
        self::assertSame(3, $query->chunk(2)->count());
        self::assertSame([null, false], $query->chunk(2)->first());
        self::assertSame([], $query->take(0)->chunk(2)->toArray());
        self::assertSame([[null, false, 3, 4, 5]], $query->chunk(20)->toArray());
        self::assertSame([[null], [false], [3], [4], [5]], $query->chunk(1)->toArray());
    }

    public function testChunkIsDeferredAndBuffersOnlyRequestedChunk(): void
    {
        $reads = 0;
        [$source] = $this->queries(Enumerable::defer(static function () use (&$reads): iterable {
            ++$reads; yield 1;
            ++$reads; yield 2;
            throw new \RuntimeException('Read beyond first chunk');
        }), []);
        $query = $source->chunk(2)->take(1);
        self::assertSame(0, $reads);
        self::assertSame([[1, 2]], $query->toArray());
        self::assertSame(2, $reads);
        self::assertSame([[1, 2]], $query->toArray());
        self::assertSame(4, $reads);
    }

    public function testInvalidChunkSizesAreRejectedAtConstruction(): void
    {
        [$query] = $this->queries([], []);
        foreach ([0, -1] as $size) {
            try {
                $query->chunk($size);
                self::fail('Expected invalid chunk size');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testBinaryOperationsRejectDifferentProvidersAtConstruction(): void
    {
        [$query] = $this->queries([], []);
        [$other] = $this->queries([], []);
        foreach (['zip', 'groupJoin'] as $method) {
            try {
                if ($method === 'zip') $query->zip($other, Expr::field('outer'));
                else $query->groupJoin($other, Expr::field('id'), Expr::field('id'), Expr::field('outer'));
                self::fail('Expected different-provider error');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testNewOperatorsRemainProviderExpressions(): void
    {
        [$outer, $inner] = $this->queries([], []);
        $queries = [
            'groupJoin' => $outer->groupJoin($inner, Expr::field('id'), Expr::field('id'), Expr::field('inner')),
            'zip' => $outer->zip($inner, Expr::field('outer')),
            'chunk' => $outer->chunk(2),
        ];
        foreach ($queries as $method => $query) {
            self::assertSame($outer->provider(), $query->provider());
            self::assertInstanceOf(MethodCallExpression::class, $query->expression());
            self::assertSame($method, $query->expression()->method);
            self::assertSame($outer->expression(), $query->expression()->source);
        }
    }
}
