<?php
declare(strict_types=1);
namespace Light\Linq\Tests;
use Light\Linq\{Expr, InMemoryQueryProvider, Queryable};
use PHPUnit\Framework\TestCase;
final class QueryOperatorsTest extends TestCase
{
    private function query(): Queryable
    {
        return Queryable::from(new InMemoryQueryProvider(['users' => [
            ['id' => 1, 'country' => 'HK', 'name' => 'Zoe', 'active' => true],
            ['id' => 2, 'country' => 'UK', 'name' => 'Ada', 'active' => false],
            ['id' => 3, 'country' => 'HK', 'name' => 'Ada', 'active' => null],
            ['id' => 4, 'country' => 'HK', 'name' => 'Ada', 'active' => true],
        ]]), 'users');
    }
    public function testSecondaryOrderingAndStableTies(): void
    {
        $q = $this->query()->orderBy(Expr::field('country'))->thenBy(Expr::field('name'));
        self::assertSame([3,4,1,2], $q->select(Expr::field('id'))->toArray());
        self::assertSame([4,3,1,2], $q->thenByDescending(Expr::field('id'))->select(Expr::field('id'))->toArray());
        self::assertSame([1,3,4,2], $this->query()->orderBy(Expr::field('country'))
            ->thenByDescending(Expr::field('name'))->select(Expr::field('id'))->toArray());
        self::assertSame([3,4,2,1], $this->query()->orderBy(Expr::field('country'))
            ->orderBy(Expr::field('name'))->select(Expr::field('id'))->toArray());
    }
    public function testThenByRequiresAnOrderedSource(): void
    {
        $this->expectException(\LogicException::class);
        $this->query()->thenBy(Expr::field('id'));
    }
    public function testFirstOrDefaultPreservesNullAndFalse(): void
    {
        $q = $this->query();
        self::assertNull($q->take(0)->firstOrDefault());
        $default = new \stdClass();
        self::assertSame($default, $q->firstOrDefault(Expr::eq(Expr::field('id'), 9), $default));
        self::assertFalse($q->where(Expr::eq(Expr::field('id'), 2))->select(Expr::field('active'))->firstOrDefault(default: true));
        self::assertNull($q->where(Expr::eq(Expr::field('id'), 3))->select(Expr::field('active'))->firstOrDefault(default: true));
        self::assertSame(2, $q->skip(1)->firstOrDefault()['id']);
    }
    public function testSingleReturnsOnlyMatchIncludingNull(): void
    {
        self::assertSame(2, $this->query()->single(Expr::eq(Expr::field('id'), 2))['id']);
        self::assertSame(1, $this->query()->take(1)->single()['id']);
        self::assertNull($this->query()->where(Expr::eq(Expr::field('id'), 3))->select(Expr::field('active'))->single());
    }
    public function testSingleRejectsEmpty(): void
    {
        $this->expectException(\UnderflowException::class);
        $this->query()->take(0)->single();
    }
    public function testSingleRejectsMultiple(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->query()->single();
    }
    public function testAllAndContainsRespectSourceOperations(): void
    {
        self::assertTrue($this->query()->all(Expr::gt(Expr::field('id'), 0)));
        self::assertTrue($this->query()->take(0)->all(Expr::eq(Expr::field('active'), true)));
        self::assertFalse($this->query()->all(Expr::eq(Expr::field('active'), true)));
        self::assertFalse($this->query()->where(Expr::eq(Expr::field('id'), 3))->all(Expr::eq(Expr::field('active'), true)));
        self::assertTrue($this->query()->select(Expr::field('active'))->contains(null));
        self::assertTrue($this->query()->select(Expr::field('active'))->contains(false));
        self::assertFalse($this->query()->take(1)->select(Expr::field('id'))->contains(2));
        self::assertTrue($this->query()->skip(1)->select(Expr::field('id'))->distinct()->contains(2));
        self::assertFalse($this->query()->take(0)->select(Expr::field('id'))->contains(1));
    }
}
