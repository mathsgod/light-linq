<?php

declare(strict_types=1);

namespace Light\Linq\Tests;

use InvalidArgumentException;
use Light\Linq\Xml\XAttribute;
use Light\Linq\Xml\XDocument;
use Light\Linq\Xml\XElement;
use Light\Linq\Xml\XName;
use PHPUnit\Framework\TestCase;

final class XDocumentTest extends TestCase
{
    public function testParseAndReadRoot(): void
    {
        $document = XDocument::parse('<root><child>value</child></root>');
        $root = $document->root();

        self::assertInstanceOf(XElement::class, $root);
        self::assertSame('root', (string) $root->name());
        self::assertSame('value', $root->element('child')?->value());
    }

    public function testLoadDocumentAndQueryWithEnumerableOperators(): void
    {
        $document = XDocument::load(__DIR__.'/fixtures/users.xml');

        $names = $document
            ->descendants('user')
            ->where(static fn (XElement $user): bool => $user->attribute('active')?->value() === 'true')
            ->select(static fn (XElement $user): ?string => $user->element('name')?->value())
            ->toArray();

        self::assertSame(['Ada'], $names);
    }

    public function testElementsOnlyReturnsDirectChildren(): void
    {
        $root = XDocument::load(__DIR__.'/fixtures/users.xml')->root();
        self::assertNotNull($root);

        self::assertSame(2, $root->elements('user')->count());
        self::assertSame(0, $root->elements('role')->count());
        self::assertSame(3, $root->descendants('role')->count());
    }

    public function testDocumentDescendantsIncludesRoot(): void
    {
        $document = XDocument::parse('<root><root><child /></root></root>');

        self::assertSame(2, $document->descendants('root')->count());
        self::assertSame(1, $document->root()?->descendants('root')->count());
    }

    public function testAttributesCanBeSelectedAndFiltered(): void
    {
        $user = XDocument::load(__DIR__.'/fixtures/users.xml')
            ->descendants('user')
            ->first();

        self::assertSame('1', $user->attribute('id')?->value());
        self::assertNull($user->attribute('missing'));
        self::assertSame(
            ['id', 'active'],
            $user->attributes()
                ->select(static fn (XAttribute $attribute): string => (string) $attribute->name())
                ->toArray(),
        );
    }

    public function testExpandedNamesMatchNamespaces(): void
    {
        $document = XDocument::parse(
            '<root xmlns="urn:default" xmlns:p="urn:people">'
            .'<p:user p:id="7"><p:name>Ada</p:name></p:user>'
            .'</root>',
        );
        $userName = new XName('user', 'urn:people');
        $user = $document->descendants($userName)->single();

        self::assertSame('{urn:people}user', (string) $user->name());
        self::assertSame('7', $user->attribute('{urn:people}id')?->value());
        self::assertSame('Ada', $user->element('{urn:people}name')?->value());
        self::assertSame(0, $document->descendants('user')->count());
    }

    public function testToXmlSerializesDocument(): void
    {
        $document = XDocument::parse('<root><child id="1">value</child></root>');
        $xml = $document->toXml();

        self::assertStringContainsString('<?xml version="1.0"', $xml);
        self::assertStringContainsString('<child id="1">value</child>', $xml);
        self::assertSame('value', XDocument::parse($xml)->descendants('child')->single()->value());
    }

    public function testMalformedXmlThrowsUsefulException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to parse XML.');
        XDocument::parse('<root>');
    }

    public function testUnreadableFileThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        XDocument::load(__DIR__.'/fixtures/missing.xml');
    }
}
