<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\{ClosureTranslator, ClosureTranslationException, InMemoryQueryProvider, Queryable};
use Light\Linq\Expressions\{LogicalExpression, ContainsExpression};
use PHPUnit\Framework\TestCase;

final class ClosureTranslatorTest extends TestCase
{
    public function testArithmeticBuildsNestedBinaryExpressionsAndExecutes(): void
    {
        $orders = Queryable::from(new InMemoryQueryProvider(['orders' => [
            (object) ['id' => 1, 'total' => 25, 'tax' => 5],
            (object) ['id' => 2, 'total' => 20, 'tax' => 3],
            (object) ['id' => 3, 'total' => 40, 'tax' => 2],
        ]]), 'orders');
        $query = $orders->where(fn($o) => $o->total + $o->tax >= 30);
        $predicate = $query->expression()->arguments['predicate'];
        self::assertSame('>=', $predicate->operator);
        self::assertInstanceOf(\Light\Linq\Expressions\BinaryExpression::class, $predicate->left);
        self::assertSame('+', $predicate->left->operator);
        self::assertSame([1, 3], $query->select(fn($o) => $o->id)->toArray());
        self::assertSame([35, 26, 44], $orders->select(fn($o) => $o->total + $o->tax * 2)->toArray());
        self::assertSame([60, 46, 84], $orders->select(fn($o) => ($o->total + $o->tax) * 2)->toArray());
        self::assertSame([10, 8.5, 19], $orders->select(fn($o) => ($o->total - $o->tax) / 2)->toArray());
        self::assertSame([0, 2, 0], $orders->select(fn($o) => $o->total % $o->tax)->toArray());
    }

    private function users(): Queryable
    {
        return Queryable::from(new InMemoryQueryProvider([
            'users'=>[
                (object)['id'=>1, 'status'=>'active', 'age'=>30],
                (object)['id'=>2, 'status'=>'inactive', 'age'=>20],
                (object)['id'=>3, 'status'=>'active', 'age'=>16],
            ],
            'roles'=>[(object)['user_id'=>1, 'name'=>'admins'], (object)['user_id'=>2, 'name'=>'admins']],
        ]), 'users');
    }

    public function testContainsAndComparisonBuildTreeAndExecute(): void
    {
        $users = $this->users();
        $adminIds = Queryable::from($users->provider(), 'roles')
            ->where(fn($r) => $r->name == 'admins')
            ->select(fn($r) => $r->user_id);
        $query = $users->where(fn($u) => $adminIds->contains($u->id) && $u->status == 'active');
        $predicate = $query->expression()->arguments['predicate'];
        self::assertInstanceOf(LogicalExpression::class, $predicate);
        self::assertInstanceOf(ContainsExpression::class, $predicate->left);
        self::assertSame($adminIds->expression(), $predicate->left->source);
        self::assertSame([1], $query->select(fn($u) => $u->id)->toArray());
    }

    public function testCaptureValuesAreReadAfresh(): void
    {
        $minimum = 18;
        $filter = function ($u) use (&$minimum) { return $u->age >= $minimum; };
        self::assertSame([1, 2], $this->users()->where($filter)->select(fn($u) => $u->id)->toArray());
        $minimum = 25;
        self::assertSame([1], $this->users()->where($filter)->select(fn($u) => $u->id)->toArray());
    }

    public function testNegationPrecedenceAndProjection(): void
    {
        $query = $this->users()->where(fn($u) => !($u->status != 'active') && ($u->age >= 18 || $u->age < 0));
        self::assertSame([['key'=>1, 'state'=>'active']], $query->select(fn($u) => ['key'=>$u->id, 'state'=>$u->status])->toArray());
        $negative = ClosureTranslator::translate(fn($u) => $u->age > -1);
        self::assertSame(-1, $negative->right->value);
    }

    public function testUnsupportedCallsAreNeverExecuted(): void
    {
        $called = false;
        $custom = function () use (&$called) { $called = true; return true; };
        try {
            $this->users()->where(fn($u) => $custom($u));
            self::fail('Expected translation failure.');
        } catch (ClosureTranslationException $error) {
            self::assertStringContainsString('FuncCall', $error->getMessage());
        }
        self::assertFalse($called);
    }

    public function testAmbiguousClosuresFailExplicitly(): void
    {
        $first = fn($u) => $u->id; $second = fn($u) => $u->age;
        $this->expectException(ClosureTranslationException::class);
        ClosureTranslator::translate($first);
    }

    public function testMultipleStatementsFail(): void
    {
        $filter = function ($u) {
            $x = 1;
            return $u->id == $x;
        };
        $this->expectException(ClosureTranslationException::class);
        ClosureTranslator::translate($filter);
    }

    public function testDifferentProvidersFail(): void
    {
        $ids = $this->users()->select(fn($u) => $u->id);
        $this->expectException(ClosureTranslationException::class);
        $this->users()->where(fn($u) => $ids->contains($u->id));
    }

    public function testEvalClosureFails(): void
    {
        $closure = eval('return fn($u) => $u->id;');
        $this->expectException(ClosureTranslationException::class);
        ClosureTranslator::translate($closure);
    }

    public function testStrictIdentityIsNotSilentlyTranslatedToSqlEquality(): void
    {
        $this->expectException(ClosureTranslationException::class);
        ClosureTranslator::translate(fn($u) => $u->id === 1);
    }
}
