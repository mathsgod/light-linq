<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\Enumerable;
use Light\Linq\Expr;
use Light\Linq\InMemoryQueryProvider;
use Light\Linq\Queryable;
use PHPUnit\Framework\TestCase;

final class EnumerableIndexTest extends TestCase
{
    public function testIndexStartsAtZeroAndPreservesValuesAndDuplicates(): void
    {
        $object = new \stdClass();
        self::assertSame([
            ['index' => 0, 'value' => null], ['index' => 1, 'value' => false],
            ['index' => 2, 'value' => $object], ['index' => 3, 'value' => 'Ada'],
            ['index' => 4, 'value' => 'Ada'],
        ], Enumerable::from([null, false, $object, 'Ada', 'Ada'])->index()->toArray());
        self::assertSame([], Enumerable::empty()->index()->toArray());
    }

    public function testIndexIgnoresSourceIteratorKeysIncludingDuplicates(): void
    {
        $source = (static function (): iterable {
            yield 'same' => 'Ada';
            yield 99 => 'Bob';
            yield 'same' => 'Cat';
        })();
        self::assertSame([
            ['index' => 0, 'value' => 'Ada'], ['index' => 1, 'value' => 'Bob'], ['index' => 2, 'value' => 'Cat'],
        ], Enumerable::from($source)->index()->toArray());
    }

    public function testIndexIsRelativeToItsPositionInQueryPipeline(): void
    {
        $source = Enumerable::from([3, 1, 2]);
        self::assertSame([
            ['index' => 0, 'value' => 2], ['index' => 1, 'value' => 3],
        ], $source->where(fn ($value) => $value > 1)->orderBy(fn ($value) => $value)->index()->toArray());
        self::assertSame([
            ['index' => 0, 'value' => 3], ['index' => 2, 'value' => 2],
        ], $source->index()->where(fn ($pair) => $pair['value'] > 1)->toArray());
        self::assertSame(['0: 1', '1: 2'], $source->skip(1)->index()
            ->select(fn ($pair) => $pair['index'].': '.$pair['value'])->toArray());
    }

    public function testIndexDefersReadsStreamsAndResetsOnEveryEnumeration(): void
    {
        $reads = 0;
        $source = Enumerable::defer(static function () use (&$reads): iterable {
            foreach (['Ada', 'Bob'] as $value) { ++$reads; yield $value; }
        });
        $query = $source->index();
        self::assertSame(0, $reads);
        self::assertSame(['index' => 0, 'value' => 'Ada'], $query->first());
        self::assertSame(1, $reads);
        self::assertSame([
            ['index' => 0, 'value' => 'Ada'], ['index' => 1, 'value' => 'Bob'],
        ], $query->toArray());
        self::assertSame(3, $reads);
        self::assertSame(['index' => 0, 'value' => 'Ada'], $query->first());
        self::assertSame(4, $reads);
    }

    public function testConcurrentEnumerationsHaveIndependentIndexes(): void
    {
        $query = Enumerable::from(['Ada', 'Bob', 'Cat'])->index();
        $first = $query->getIterator();
        $second = $query->getIterator();
        self::assertSame(['index' => 0, 'value' => 'Ada'], $first->current());
        $first->next();
        self::assertSame(['index' => 1, 'value' => 'Bob'], $first->current());
        self::assertSame(['index' => 0, 'value' => 'Ada'], $second->current());
        $second->next();
        self::assertSame(['index' => 1, 'value' => 'Bob'], $second->current());
    }

    public function testTakeDoesNotReadAheadAndCanHandleUnboundedSources(): void
    {
        $source = Enumerable::defer(static function (): iterable {
            yield 10;
            throw new \RuntimeException('Read too far');
        });
        self::assertSame([['index' => 0, 'value' => 10]], $source->index()->take(1)->toArray());
        $infinite = Enumerable::defer(static function (): iterable {
            while (true) yield 'x';
        });
        self::assertSame([
            ['index' => 0, 'value' => 'x'], ['index' => 1, 'value' => 'x'], ['index' => 2, 'value' => 'x'],
        ], $infinite->index()->take(3)->toArray());
    }

    public function testSourceFailureIsPropagatedOnEnumeration(): void
    {
        $failure = new \RuntimeException('Source failed');
        $query = Enumerable::defer(static function () use ($failure): iterable {
            yield 1;
            throw $failure;
        })->index();
        try {
            $query->toArray();
            self::fail('Expected source failure');
        } catch (\RuntimeException $error) {
            self::assertSame($failure, $error);
        }
    }

    public function testProviderResultsCanBeIndexedAfterAsEnumerable(): void
    {
        $provider = new InMemoryQueryProvider(['names' => [['name' => 'Bob'], ['name' => 'Ada']]]);
        $query = Queryable::from($provider, 'names')->orderBy(Expr::field('name'))
            ->select(Expr::field('name'))->asEnumerable()->index();
        self::assertSame([
            ['index' => 0, 'value' => 'Ada'], ['index' => 1, 'value' => 'Bob'],
        ], $query->toArray());
    }
}
