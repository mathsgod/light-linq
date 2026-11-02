<?php

declare(strict_types=1);

namespace Light\Linq\Xml;

use DOMDocument;
use Light\Linq\Enumerable;
use Light\Linq\IEnumerable;

final class XDocument
{
    private function __construct(private readonly DOMDocument $document)
    {
    }

    public static function parse(string $xml): self
    {
        if ($xml === '') {
            throw new \InvalidArgumentException('XML input cannot be empty.');
        }
        $document = self::newDocument();
        self::withXmlErrors(
            static fn (): bool => $document->loadXML($xml, LIBXML_NONET),
            'Unable to parse XML.',
        );
        return new self($document);
    }

    public static function load(string $path): self
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException("XML file is not readable: {$path}");
        }
        $document = self::newDocument();
        self::withXmlErrors(
            static fn (): bool => $document->load($path, LIBXML_NONET),
            "Unable to load XML file: {$path}",
        );
        return new self($document);
    }

    public function root(): ?XElement
    {
        return $this->document->documentElement === null
            ? null
            : new XElement($this->document->documentElement);
    }

    /** @return IEnumerable<XElement> */
    public function descendants(XName|string|null $name = null): IEnumerable
    {
        $document = $this->document;
        $name = $name === null ? null : XName::from($name);
        return Enumerable::defer(static function () use ($document, $name): iterable {
            $root = $document->documentElement;
            if ($root === null) {
                return;
            }
            if ($name === null || $name->matches($root)) {
                yield new XElement($root);
            }
            yield from (new XElement($root))->descendants($name);
        });
    }

    public function toXml(bool $formatOutput = false): string
    {
        $previous = $this->document->formatOutput;
        $this->document->formatOutput = $formatOutput;
        try {
            $xml = $this->document->saveXML();
            if ($xml === false) {
                throw new \RuntimeException('Unable to serialize XML document.');
            }
            return $xml;
        } finally {
            $this->document->formatOutput = $previous;
        }
    }

    private static function newDocument(): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        return $document;
    }

    /** @param callable(): bool $operation */
    private static function withXmlErrors(callable $operation, string $message): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            if ($operation()) {
                return;
            }
            $errors = libxml_get_errors();
            $detail = $errors === [] ? '' : ' '.trim($errors[0]->message);
            throw new \InvalidArgumentException($message.$detail);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
