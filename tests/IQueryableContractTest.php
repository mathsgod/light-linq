<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\{IQueryable, Queryable, InMemoryQueryProvider};
use PHPUnit\Framework\TestCase;

final class IQueryableContractTest extends TestCase
{
    public function testAnyAcceptsClosureThroughInterface(): void
    {
        $query = Queryable::from(new InMemoryQueryProvider(['users'=>[(object)['id'=>1],(object)['id'=>2]]]),'users');
        $test = static fn(IQueryable $source, \Closure $predicate) => $source->any($predicate);
        self::assertTrue($test($query, fn($u) => $u->id == 2));
        self::assertFalse($test($query, fn($u) => $u->id == 3));
        self::assertTrue($query->any());
        self::assertFalse($query->take(0)->any());
    }
}
