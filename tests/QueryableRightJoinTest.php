<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\Enumerable;
use Light\Linq\Expr;
use Light\Linq\Expressions\Expression;
use Light\Linq\InMemoryQueryProvider;
use Light\Linq\IQueryProvider;
use Light\Linq\Queryable;
use PHPUnit\Framework\TestCase;

final class QueryableRightJoinTest extends TestCase
{
    private function join(iterable $left, iterable $right): Queryable
    {
        $provider = new InMemoryQueryProvider(['left' => $left, 'right' => $right]);
        return Queryable::from($provider, 'left')->rightJoin(
            Queryable::from($provider, 'right'), Expr::field('id'), Expr::field('id'),
            Expr::projection(['left' => 'outer.name', 'right' => 'inner.name']),
        );
    }

    public function testPreservesRightRowsDuplicatesAndMatchOrdering(): void
    {
        $query = $this->join(
            [['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => 'Bob'], ['id' => 1, 'name' => 'Ada2'], ['id' => 3, 'name' => 'Cara']],
            [['id' => 1, 'name' => 'A'], ['id' => 9, 'name' => 'D'], ['id' => 1, 'name' => 'B']],
        );
        $expected = [
            ['left' => 'Ada', 'right' => 'A'], ['left' => 'Ada2', 'right' => 'A'],
            ['left' => null, 'right' => 'D'],
            ['left' => 'Ada', 'right' => 'B'], ['left' => 'Ada2', 'right' => 'B'],
        ];
        self::assertSame($expected, $query->toArray());
        self::assertSame($expected, $query->toArray());
        self::assertSame(5, $query->count());
        self::assertSame(['D'], $query->where(Expr::eq(Expr::field('left'), null))->select(Expr::field('right'))->toArray());
    }

    public function testNullAndStrictKeysUseExistingQueryableRelationalRules(): void
    {
        self::assertSame([
            ['left' => null, 'right' => 'null'], ['left' => null, 'right' => 'string'], ['left' => 'int', 'right' => 'int'],
        ], $this->join(
            [['id' => null, 'name' => 'null'], ['id' => 1, 'name' => 'int']],
            [['id' => null, 'name' => 'null'], ['id' => '1', 'name' => 'string'], ['id' => 1, 'name' => 'int']],
        )->toArray());
        self::assertSame([['left' => null, 'right' => 'null']], $this->join(
            [['id' => ['a' => 1, 'b' => null], 'name' => 'null']],
            [['id' => ['a' => 1, 'b' => null], 'name' => 'null']],
        )->toArray());
        self::assertSame([['left' => 'match', 'right' => 'match']], $this->join(
            [['id' => ['a' => 1], 'name' => 'match']], [['id' => ['a' => 1], 'name' => 'match']],
        )->toArray());
    }

    public function testEmptyRightDoesNotExecuteOrReadLeftQuery(): void
    {
        $provider = new InMemoryQueryProvider(['right' => []]);
        $query = Queryable::from($provider, 'missing')->orderBy(Expr::field('id'))->rightJoin(
            Queryable::from($provider, 'right'), Expr::field('id'), Expr::field('id'), Expr::field('inner'),
        );
        self::assertSame([], $query->toArray());
        self::assertSame([['left' => null, 'right' => 'R']], $this->join([], [['id' => 1, 'name' => 'R']])->toArray());
    }

    public function testConstructionDoesNotExecuteAndProviderMismatchFailsEarly(): void
    {
        $provider = new class implements IQueryProvider {
            public int $calls = 0;
            public function execute(Expression $expression): mixed { ++$this->calls; return []; }
        };
        $left = Queryable::from($provider, 'left');
        $right = Queryable::from($provider, 'right');
        $joined = $left->rightJoin($right, Expr::field('id'), Expr::field('id'), Expr::field('inner'));
        self::assertSame(0, $provider->calls);
        self::assertNotSame($left, $joined);
        self::assertSame('rightJoin', $joined->expression()->method);
        self::assertSame($left->expression(), $joined->expression()->source);
        self::assertSame($right->expression(), $joined->expression()->arguments['inner']);
        $this->expectException(\InvalidArgumentException::class);
        $left->rightJoin(Queryable::from(new InMemoryQueryProvider([]), 'right'), Expr::field('id'), Expr::field('id'), Expr::field('inner'));
    }

    public function testBuffersLeftOnceAndStreamsRightWithoutReadingAhead(): void
    {
        $leftReads = $rightReads = 0;
        $left = Enumerable::defer(static function () use (&$leftReads): iterable {
            foreach ([1, 1, 2] as $id) { ++$leftReads; yield ['id' => $id, 'name' => 'L']; }
        });
        $right = Enumerable::defer(static function () use (&$rightReads): iterable {
            ++$rightReads;
            yield ['id' => 1, 'name' => 'R'];
            throw new \RuntimeException('Must not read ahead');
        });
        $query = $this->join($left, $right);
        self::assertSame([0, 0], [$leftReads, $rightReads]);
        self::assertSame([['left' => 'L', 'right' => 'R']], $query->take(1)->toArray());
        self::assertSame([3, 1], [$leftReads, $rightReads]);
    }

    public function testSingleUseGeneratorsAndRepeatedKeysAreNotRewound(): void
    {
        $left = (static function (): iterable { yield 'x' => ['id' => 1, 'name' => 'L']; })();
        $right = (static function (): iterable {
            yield 'x' => ['id' => 1, 'name' => 'R1'];
            yield 'x' => ['id' => 1, 'name' => 'R2'];
        })();
        self::assertSame([['left' => 'L', 'right' => 'R1'], ['left' => 'L', 'right' => 'R2']], $this->join($left, $right)->toArray());
    }
}
