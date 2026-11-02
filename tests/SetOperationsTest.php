<?php

declare(strict_types=1);
namespace Light\Linq\Tests;

use Light\Linq\{Expr, InMemoryQueryProvider, Queryable};
use PHPUnit\Framework\TestCase;

final class SetOperationsTest extends TestCase
{
    private function queries(): array
    {
        $p = new InMemoryQueryProvider(['a' => [1,2,2,3,null,null], 'b' => [2,3,4,null], 'empty' => []]);
        return [Queryable::from($p, 'a'), Queryable::from($p, 'b'), Queryable::from($p, 'empty')];
    }

    public function testFourOperationsHandleDuplicatesNullAndDirection(): void
    {
        [$a, $b] = $this->queries();
        self::assertSame([1,2,2,3,null,null,2,3,4,null], $a->concat($b)->toArray());
        self::assertSame([1,2,3,null,4], $a->union($b)->toArray());
        self::assertSame([2,3,null], $a->intersect($b)->toArray());
        self::assertSame([1], $a->except($b)->toArray());
        self::assertSame([4], $b->except($a)->toArray());
    }

    public function testEmptyInputsAndOperationOrder(): void
    {
        [$a, $b, $empty] = $this->queries();
        self::assertSame($a->toArray(), $a->concat($empty)->toArray());
        self::assertSame([1,2,3,null], $a->except($empty)->toArray());
        self::assertSame([], $a->intersect($empty)->toArray());
        self::assertSame([], $empty->except($a)->toArray());
        self::assertSame([2,3,4,null], $empty->union($b)->toArray());
        self::assertSame([1,2,3,4,null], $a->take(1)->union($b)->toArray());
        self::assertSame([1], $a->union($b)->take(1)->toArray());
        self::assertSame([1,4], $a->except($b)->concat($b->except($a))->toArray());
    }

    public function testWholeRowsAreComparedAndResultsRemainComposable(): void
    {
        $p = new InMemoryQueryProvider([
            'a' => [['k'=>1,'v'=>null], ['k'=>1,'v'=>null], ['k'=>1,'v'=>'x'], ['k'=>null,'v'=>null]],
            'b' => [['k'=>1,'v'=>null], ['k'=>null,'v'=>null]],
        ]);
        $a = Queryable::from($p, 'a')->select(Expr::fields('k', 'v'));
        $b = Queryable::from($p, 'b')->select(Expr::fields('k', 'v'));
        self::assertSame([['k'=>1,'v'=>null], ['k'=>null,'v'=>null]], $a->intersect($b)->toArray());
        self::assertSame([['k'=>1,'v'=>'x']], $a->except($b)->toArray());
        self::assertSame([1], $a->union($b)->where(Expr::neq(Expr::field('v'), null))->select(Expr::field('k'))->toArray());
        self::assertSame(3, $a->union($b)->count());
    }

    public function testConcatAndUnionDoNotReadSecondUntilNeeded(): void
    {
        foreach (['concat','union'] as $method) {
            $second = (static function () { throw new \RuntimeException('Second source must not be read.'); yield ['id'=>2]; })();
            $p = new InMemoryQueryProvider(['a'=>[['id'=>1]],'b'=>$second]);
            $a = Queryable::from($p, 'a');
            $b = Queryable::from($p, 'b')->orderBy(Expr::field('id'));
            self::assertSame([['id'=>1]], $a->$method($b)->take(1)->toArray());
        }
    }

    public function testSameProviderIsRequired(): void
    {
        [$a] = $this->queries();
        $this->expectException(\InvalidArgumentException::class);
        $a->union(Queryable::from(new InMemoryQueryProvider(['b'=>[]]), 'b'));
    }
}
