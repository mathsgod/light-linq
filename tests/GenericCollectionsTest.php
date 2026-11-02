<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use OutOfRangeException;
use Light\Linq\Collections\Generic\GenericList;
use Light\Linq\Collections\Generic\Queue;
use Light\Linq\Collections\Generic\Stack;
use Light\Linq\Enumerable;
use PHPUnit\Framework\TestCase;
use UnderflowException;

final class GenericCollectionsTest extends TestCase
{
    public function testGenericListMutationAndIndexing(): void
    {
        /** @var GenericList<string> $list */
        $list = new GenericList(['Ada', 'Bob']);
        $list->add('Dan');
        $list->insert(2, 'Cara');
        $list[1] = 'Bobby';
        $list[] = 'Eve';

        self::assertSame(['Ada', 'Bobby', 'Cara', 'Dan', 'Eve'], $list->toArray());
        self::assertSame('Cara', $list[2]);
        self::assertSame(3, $list->indexOf('Dan'));
        self::assertTrue($list->contains('Bobby'));
        self::assertTrue($list->remove('Cara'));
        $list->removeAt(0);
        self::assertSame(['Bobby', 'Dan', 'Eve'], $list->toArray());
        self::assertCount(3, $list);
    }

    public function testGenericListRejectsInvalidIndex(): void
    {
        $list = new GenericList([1]);

        $this->expectException(OutOfRangeException::class);
        $list->removeAt(1);
    }

    public function testGenericListClear(): void
    {
        $list = new GenericList([1, 2]);
        $list->clear();

        self::assertSame([], $list->toArray());
        self::assertCount(0, $list);
    }

    public function testGenericListRangeOperations(): void
    {
        $list = new GenericList([1, 2, 5]);
        $list->insertRange(2, [3, 4]);

        self::assertSame([1, 2, 3, 4, 5], $list->toArray());
        self::assertSame([2, 3, 4], $list->getRange(1, 3)->toArray());

        $list->removeRange(1, 3);
        self::assertSame([1, 5], $list->toArray());
    }

    public function testGenericListCanInsertItself(): void
    {
        $list = new GenericList([1, 2]);
        $list->insertRange(1, $list);

        self::assertSame([1, 1, 2, 2], $list->toArray());
    }

    public function testGenericListRejectsInvalidRange(): void
    {
        $list = new GenericList([1, 2]);

        $this->expectException(OutOfRangeException::class);
        $list->getRange(1, 2);
    }

    public function testStackUsesLastInFirstOutOrder(): void
    {
        /** @var Stack<int> $stack */
        $stack = new Stack();
        $stack->push(1);
        $stack->push(2);
        $stack->push(3);

        self::assertSame(3, $stack->peek());
        self::assertSame([3, 2, 1], $stack->toArray());
        self::assertSame(3, $stack->pop());
        self::assertTrue($stack->tryPop($value));
        self::assertSame(2, $value);
        self::assertCount(1, $stack);
    }

    public function testTryPopReturnsFalseForEmptyStack(): void
    {
        $stack = new Stack();
        $value = 'unchanged';

        self::assertFalse($stack->tryPop($value));
        self::assertNull($value);
    }

    public function testPopRejectsEmptyStack(): void
    {
        $this->expectException(UnderflowException::class);
        (new Stack())->pop();
    }

    public function testQueueUsesFirstInFirstOutOrder(): void
    {
        /** @var Queue<int> $queue */
        $queue = new Queue([1, 2]);
        $queue->enqueue(3);

        self::assertSame(1, $queue->peek());
        self::assertSame(1, $queue->dequeue());
        self::assertTrue($queue->tryDequeue($value));
        self::assertSame(2, $value);
        self::assertSame([3], $queue->toArray());
        self::assertCount(1, $queue);
    }

    public function testTryDequeueReturnsFalseForEmptyQueue(): void
    {
        $queue = new Queue();
        $value = 'unchanged';

        self::assertFalse($queue->tryDequeue($value));
        self::assertNull($value);
    }

    public function testDequeueRejectsEmptyQueue(): void
    {
        $this->expectException(UnderflowException::class);
        (new Queue())->dequeue();
    }

    public function testCollectionsCanBeQueriedAsEnumerable(): void
    {
        $list = new GenericList([1, 2, 3, 4]);
        $stack = new Stack([1, 2, 3]);
        $queue = new Queue([1, 2, 3]);

        self::assertSame(
            [20, 40],
            Enumerable::from($list)
                ->where(static fn (int $number): bool => $number % 2 === 0)
                ->select(static fn (int $number): int => $number * 10)
                ->toArray(),
        );
        self::assertSame([3, 2, 1], Enumerable::from($stack)->toArray());
        self::assertSame([1, 2, 3], Enumerable::from($queue)->toArray());
    }

    public function testMutationDuringIterationThrows(): void
    {
        $list = new GenericList([1]);
        $iterator = $list->getIterator();
        $iterator->rewind();
        $list->add(2);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The list was modified during iteration.');
        $iterator->next();
    }
}
