<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\ClosureTranslationException;
use Light\Linq\Expr;
use Light\Linq\Expressions\Expression;
use Light\Linq\Expressions\FieldExpression;
use Light\Linq\InMemoryQueryProvider;
use Light\Linq\IQueryable;
use Light\Linq\IQueryProvider;
use Light\Linq\Queryable;
use PHPUnit\Framework\TestCase;

final class QueryableClosureSelectorsTest extends TestCase
{
    private function people(): IQueryable
    {
        return Queryable::from(new InMemoryQueryProvider(['people' => [
            ['id' => 1, 'name' => 'Ada', 'city' => 'HK', 'age' => 30],
            ['id' => 2, 'name' => 'Bob', 'city' => 'TW', 'age' => 20],
            ['id' => 3, 'name' => 'Cara', 'city' => 'HK', 'age' => 40],
        ]]), 'people');
    }

    public function testOrderingClosuresWorkThroughInterfaceAndMixWithExpressions(): void
    {
        $people = $this->people();
        self::assertSame(['Bob', 'Ada', 'Cara'], $people->orderBy(fn ($p) => $p->age)->select(Expr::field('name'))->toArray());
        self::assertSame(['Cara', 'Ada', 'Bob'], $people->orderByDescending(fn ($p) => $p->age)->select(Expr::field('name'))->toArray());
        self::assertSame(['Cara', 'Ada', 'Bob'], $people->orderBy(fn ($p) => $p->city)
            ->thenByDescending(fn ($p) => $p->age)->select(Expr::field('name'))->toArray());
        self::assertSame(['Ada', 'Cara', 'Bob'], $people->orderBy(Expr::field('city'))
            ->thenBy(fn ($p) => $p->age)->select(Expr::field('name'))->toArray());
    }

    public function testGroupingClosuresProduceSameTreeAndResults(): void
    {
        $people = $this->people();
        $query = $people->groupBy(fn ($p) => $p->city);
        self::assertEquals($people->groupBy(Expr::field('city'))->expression(), $query->expression());
        self::assertSame(['HK', 'TW'], $query->select(Expr::key())->toArray());
        self::assertSame([2, 1], $query->select(Expr::count())->toArray());
        self::assertSame(['hk', 'tw'], $people->groupBy(fn ($p) => strtolower($p->city))->select(Expr::key())->toArray());
    }

    public function testAggregateClosuresAcceptComputedSelectorsAndNulls(): void
    {
        $people = $this->people();
        self::assertSame(90, $people->sum(fn ($p) => $p->age));
        self::assertSame(30, $people->average(fn ($p) => $p->age));
        self::assertSame(20, $people->min(fn ($p) => $p->age));
        self::assertSame(40, $people->max(fn ($p) => $p->age));
        self::assertSame(180, $people->sum(fn ($p) => $p->age * 2));
        $query = Queryable::from(new InMemoryQueryProvider(['rows' => [['n' => null], ['n' => 2], ['n' => 4]]]), 'rows');
        self::assertSame(3, $query->average(fn ($row) => $row->n));
        self::assertNull($query->take(0)->average(fn ($row) => $row->n));
    }

    public function testTerminalPredicateClosuresAndNamedDefaults(): void
    {
        $people = $this->people();
        self::assertSame('Bob', $people->first(fn ($p) => $p->age < 30)['name']);
        self::assertSame('Ada', $people->firstOrDefault(fn ($p) => $p->city == 'HK')['name']);
        self::assertSame('Bob', $people->single(fn ($p) => $p->city == 'TW')['name']);
        self::assertSame('Bob', $people->singleOrDefault(fn ($p) => $p->city == 'TW')['name']);
        self::assertSame('Cara', $people->last(fn ($p) => $p->city == 'HK')['name']);
        self::assertSame('Cara', $people->lastOrDefault(fn ($p) => $p->city == 'HK')['name']);
        self::assertTrue($people->all(fn ($p) => $p->age >= 20));
        self::assertFalse($people->all(fn ($p) => $p->age > 20));
        foreach (['firstOrDefault', 'singleOrDefault', 'lastOrDefault'] as $method) {
            self::assertSame('missing', $people->$method(fn ($p) => $p->id == 99, default: 'missing'));
        }
        self::assertSame('Ada', $people->first()['name']);
        self::assertTrue($people->take(0)->all(fn ($p) => $p->age > 20));
    }

    public function testAllJoinKeySelectorsAcceptClosures(): void
    {
        $provider = new InMemoryQueryProvider([
            'people' => [['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => 'Bob']],
            'orders' => [['customerId' => 1, 'id' => 10]],
        ]);
        $people = Queryable::from($provider, 'people');
        $orders = Queryable::from($provider, 'orders');
        foreach (['join', 'leftJoin', 'rightJoin', 'groupJoin'] as $method) {
            $result = $method === 'groupJoin' ? Expr::field('outer.name') : Expr::projection(['name' => 'outer.name', 'order' => 'inner.id']);
            $query = $people->$method($orders,
                fn ($person) => $person->id,
                fn ($order) => $order->customerId,
                $result,
            );
            $explicit = $people->$method($orders, Expr::field('id'), Expr::field('customerId'), $result);
            self::assertEquals($explicit->expression(), $query->expression());
            self::assertSame($explicit->toArray(), $query->toArray());
        }
    }

    public function testBuildingQueriesOnlyTranslatesAndNeverExecutesProvider(): void
    {
        $provider = new class implements IQueryProvider {
            public int $calls = 0;
            public function execute(Expression $expression): mixed { ++$this->calls; return 0; }
        };
        $query = Queryable::from($provider, 'people');
        $query->orderBy(fn ($p) => $p->age)
            ->thenBy(fn ($p) => $p->name);
        $query->groupBy(fn ($p) => $p->city);
        self::assertSame(0, $provider->calls);
        self::assertInstanceOf(FieldExpression::class, $query->groupBy(fn ($p) => $p->city)->expression()->arguments['keySelector']);
        $query->sum(fn ($p) => $p->age);
        self::assertSame(1, $provider->calls);
    }

    public function testUnsupportedSyntaxFailsDuringTranslationAndIndexIsNotAccepted(): void
    {
        $people = $this->people();
        $bad = fn ($p) => md5($p->name);
        foreach (['orderBy', 'groupBy', 'sum', 'first', 'all'] as $method) {
            try { $people->$method($bad); self::fail('Expected translation failure'); }
            catch (ClosureTranslationException) { self::assertTrue(true); }
        }
        $this->expectException(ClosureTranslationException::class);
        $people->orderBy(fn ($p, $i) => $i);
    }
}
