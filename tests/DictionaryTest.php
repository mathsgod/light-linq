<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use Light\Linq\Collections\Generic\Dictionary;
use Light\Linq\Collections\Generic\EqualityComparer;
use Light\Linq\Collections\Generic\KeyNotFoundException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DictionaryTest extends TestCase
{
    public function testAddIndexerAndReplace(): void
    {
        /** @var Dictionary<string, int> $scores */
        $scores = new Dictionary();
        $scores->add('Ada', 10);
        $scores['Bob'] = 20;
        $scores['Ada'] = 15;

        self::assertCount(2, $scores);
        self::assertSame(15, $scores->get('Ada'));
        self::assertFalse($scores->tryAdd('Ada', 99));
    }

    public function testTryGetValueAndRemove(): void
    {
        /** @var Dictionary<string, int> $scores */
        $scores = new Dictionary();
        $scores->add('Bob', 20);

        self::assertTrue($scores->tryGetValue('Bob', $score));
        self::assertSame(20, $score);
        self::assertTrue($scores->remove('Bob'));
        self::assertFalse($scores->containsKey('Bob'));
    }

    public function testObjectKeysUseIdentityByDefault(): void
    {
        $firstKey = (object) ['id' => 1];
        $sameLookingKey = (object) ['id' => 1];
        /** @var Dictionary<object, string> $objects */
        $objects = new Dictionary();
        $objects->add($firstKey, 'first');

        self::assertTrue($objects->containsKey($firstKey));
        self::assertFalse($objects->containsKey($sameLookingKey));
    }

    public function testCustomComparer(): void
    {
        $caseInsensitive = new class implements EqualityComparer {
            public function equals(mixed $left, mixed $right): bool
            {
                return is_string($left)
                    && is_string($right)
                    && strtolower($left) === strtolower($right);
            }

            public function hash(mixed $value): string
            {
                return hash('xxh128', strtolower((string) $value));
            }
        };

        /** @var Dictionary<string, string> $headers */
        $headers = new Dictionary($caseInsensitive);
        $headers->add('Content-Type', 'application/json');

        self::assertSame('application/json', $headers['content-type']);
    }

    public function testMissingKeyThrows(): void
    {
        $dictionary = new Dictionary();

        $this->expectException(KeyNotFoundException::class);
        $dictionary->get('Nobody');
    }

    public function testMutationDuringIterationThrows(): void
    {
        /** @var Dictionary<string, int> $scores */
        $scores = new Dictionary();
        $scores->add('Ada', 10);
        $iterator = $scores->getIterator();
        $iterator->rewind();
        $scores->add('Cara', 30);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The dictionary was modified during iteration.');
        $iterator->next();
    }
}
