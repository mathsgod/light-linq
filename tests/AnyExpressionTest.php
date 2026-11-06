<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\{Expr, ClosureTranslationException, InMemoryQueryProvider, Queryable};
use Light\Linq\Expressions\{AnyExpression, OuterFieldExpression};
use PHPUnit\Framework\TestCase;

final class AnyExpressionTest extends TestCase
{
    private function queries(): array
    {
        $provider = new InMemoryQueryProvider([
            'users'=>[
                (object)['id'=>1, 'status'=>'active'],
                (object)['id'=>2, 'status'=>'inactive'],
                (object)['id'=>3, 'status'=>'active'],
            ],
            'roles'=>[
                (object)['id'=>10, 'user_id'=>1, 'name'=>'admins'],
                (object)['id'=>11, 'user_id'=>1, 'name'=>'admins'],
                (object)['id'=>12, 'user_id'=>2, 'name'=>'admins'],
                (object)['id'=>13, 'user_id'=>3, 'name'=>'users'],
            ],
            'permissions'=>[(object)['role_id'=>10, 'user_id'=>1]],
            'empty'=>[],
        ]);
        return array_map(fn($name) => Queryable::from($provider, $name), ['users', 'roles', 'permissions', 'empty']);
    }

    public function testCorrelatedAnyBuildsTreeWithoutExecutingAndFilters(): void
    {
        [$users, $roles] = $this->queries();
        $query = $users->where(fn($u) => $roles->any(fn($r) => $r->user_id == $u->id && $r->name == 'admins') && $u->status == 'active');
        $exists = $query->expression()->arguments['predicate']->left;
        self::assertInstanceOf(AnyExpression::class, $exists);
        $outer = $exists->source->arguments['predicate']->left->right;
        self::assertInstanceOf(OuterFieldExpression::class, $outer);
        self::assertSame('id', $outer->path);
        self::assertSame(1, $outer->depth);
        self::assertSame([1], $query->select(fn($u) => $u->id)->toArray());
        self::assertSame([1], $query->select(fn($u) => $u->id)->toArray());
    }

    public function testNestedAnyCanReferenceBothOuterLevels(): void
    {
        [$users, $roles, $permissions] = $this->queries();
        $query = $users->where(fn($u) => $roles->any(
            fn($r) => $r->user_id == $u->id && $permissions->any(
                fn($p) => $p->role_id == $r->id && $p->user_id == $u->id
            )
        ));
        $first = $query->expression()->arguments['predicate'];
        $second = $first->source->arguments['predicate']->right;
        self::assertSame(1, $second->source->arguments['predicate']->left->right->depth);
        self::assertSame(2, $second->source->arguments['predicate']->right->right->depth);
        self::assertSame([1], $query->select(fn($u) => $u->id)->toArray());
    }

    public function testEmptyUncorrelatedAndNegatedAny(): void
    {
        [$users, $roles, , $empty] = $this->queries();
        self::assertSame(3, $users->where(fn($u) => $roles->any())->count());
        self::assertSame(0, $users->where(fn($u) => $empty->any())->count());
        self::assertSame(3, $users->where(fn($u) => !$empty->any())->count());
        $query = $users->where(fn($u) => !$roles->any(fn($r) => $r->user_id == $u->id && $r->name == 'admins'));
        self::assertSame([3], $query->select(fn($u) => $u->id)->toArray());
    }

    public function testInnerParameterShadowsOuterParameter(): void
    {
        [$users, $roles] = $this->queries();
        $query = $users->where(fn($u) => $roles->any(
            fn($u) => $u->user_id == 1
        ));
        self::assertSame(3, $query->count());
    }

    public function testExplicitUseAndCapturedScalars(): void
    {
        [$users, $roles] = $this->queries();
        $name = 'admins';
        $query = $users->where(fn($u) => $roles->any(function ($r) use ($u, $name) {
            return $r->user_id == $u->id && $r->name == $name;
        }));
        self::assertSame([1,2], $query->select(fn($u) => $u->id)->toArray());
    }

    public function testMissingExplicitUseIsRejected(): void
    {
        [$users, $roles] = $this->queries();
        $this->expectException(ClosureTranslationException::class);
        $users->where(fn($u) => $roles->any(function ($r) {
            return $r->user_id == $u->id;
        }));
    }

    public function testDifferentProviderIsRejected(): void
    {
        [$users] = $this->queries();
        [, $roles] = $this->queries();
        $this->expectException(ClosureTranslationException::class);
        $users->where(fn($u) => $roles->any(fn($r) => $r->user_id == $u->id));
    }

    public function testUnsupportedInnerCallIsNotExecuted(): void
    {
        [$users, $roles] = $this->queries();
        $this->expectException(ClosureTranslationException::class);
        $users->where(fn($u) => $roles->any(fn($r) => nonexistent_exists_test_function($r)));
    }

    public function testExplicitExpressionApi(): void
    {
        [$users, $roles] = $this->queries();
        $query = $users->where(Expr::any($roles->where(Expr::both(
            Expr::eq(Expr::field('user_id'), Expr::outerField('id')),
            Expr::eq(Expr::field('name'), 'admins'),
        ))));
        self::assertSame([1,2], $query->select(fn($u) => $u->id)->toArray());
    }

    public function testUnboundOuterFieldFails(): void
    {
        [$users] = $this->queries();
        $this->expectException(\LogicException::class);
        $users->where(Expr::eq(Expr::field('id'), Expr::outerField('id')))->toArray();
    }

    public function testSameSourceCorrelation(): void
    {
        [$users] = $this->queries();
        $query = $users->where(fn($u) => $users->any(fn($other) => $other->id > $u->id));
        self::assertSame([1,2], $query->select(fn($u) => $u->id)->toArray());
    }

    public function testTranslationDoesNotExecuteProvider(): void
    {
        $provider = new class implements \Light\Linq\IQueryProvider {
            public function execute(\Light\Linq\Expressions\Expression $expression): mixed
            {
                throw new \RuntimeException('Provider must not execute while constructing expressions.');
            }
        };
        $users = Queryable::from($provider, 'users');
        $roles = Queryable::from($provider, 'roles');
        $query = $users->where(fn($u) => $roles->any(fn($r) => $r->user_id == $u->id));
        self::assertInstanceOf(AnyExpression::class, $query->expression()->arguments['predicate']);
    }

    public function testExplicitUsePreservesGrandparentDepth(): void
    {
        [$users, $roles, $permissions] = $this->queries();
        $query = $users->where(fn($u) => $roles->any(
            fn($r) => $r->user_id == $u->id && $permissions->any(function ($p) use ($u) {
                return $p->user_id == $u->id;
            })
        ));
        $outerField = $query->expression()->arguments['predicate']->source->arguments['predicate']
            ->right->source->arguments['predicate']->right;
        self::assertSame(2, $outerField->depth);
        self::assertSame([1], $query->select(fn($u) => $u->id)->toArray());
    }

    public function testMalformedAnyPredicatesFailDuringTranslation(): void
    {
        [$users, $roles] = $this->queries();
        $invalid = [
            fn($u) => $roles->any(true),
            fn($u) => $roles->any(fn($r, $extra) => true),
            fn($u) => $roles->any(predicate: fn($r) => true),
            fn($u) => $roles->any(fn($r) => true, false),
            fn($u) => $roles->any(function ($r) { $x = 1; return $x; }),
        ];
        foreach ($invalid as $closure) {
            try {
                $users->where($closure);
                self::fail('Expected invalid any() to fail during translation.');
            } catch (ClosureTranslationException $error) {
                self::assertStringContainsString('any()', $error->getMessage());
            }
        }
    }

    public function testInvalidDepthFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Expr::outerField('id', 0);
    }
}
