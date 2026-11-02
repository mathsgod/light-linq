<?php

declare(strict_types=1);

namespace Light\Linq\Xml;

use DOMAttr;
use DOMElement;
use DOMNode;
use Light\Linq\Enumerable;
use Light\Linq\IEnumerable;

final class XElement extends XNode
{
    public function __construct(private readonly DOMElement $elementNode)
    {
        parent::__construct($elementNode);
    }

    public function name(): XName
    {
        return new XName(
            $this->elementNode->localName ?? $this->elementNode->tagName,
            $this->elementNode->namespaceURI ?? '',
        );
    }

    public function value(): string
    {
        return $this->elementNode->textContent;
    }

    public function element(XName|string $name): ?self
    {
        $name = XName::from($name);
        foreach ($this->elementNode->childNodes as $child) {
            if ($child instanceof DOMElement && $name->matches($child)) {
                return new self($child);
            }
        }
        return null;
    }

    /** @return IEnumerable<XElement> */
    public function elements(XName|string|null $name = null): IEnumerable
    {
        $element = $this->elementNode;
        $name = $name === null ? null : XName::from($name);
        return Enumerable::defer(static function () use ($element, $name): iterable {
            foreach ($element->childNodes as $child) {
                if ($child instanceof DOMElement && ($name === null || $name->matches($child))) {
                    yield new XElement($child);
                }
            }
        });
    }

    /** @return IEnumerable<XElement> */
    public function descendants(XName|string|null $name = null): IEnumerable
    {
        $element = $this->elementNode;
        $name = $name === null ? null : XName::from($name);
        return Enumerable::defer(static function () use ($element, $name): iterable {
            yield from self::descendantElements($element, $name);
        });
    }

    public function attribute(XName|string $name): ?XAttribute
    {
        $name = XName::from($name);
        foreach ($this->elementNode->attributes as $attribute) {
            if ($attribute instanceof DOMAttr && $name->matches($attribute)) {
                return new XAttribute($attribute);
            }
        }
        return null;
    }

    /** @return IEnumerable<XAttribute> */
    public function attributes(XName|string|null $name = null): IEnumerable
    {
        $element = $this->elementNode;
        $name = $name === null ? null : XName::from($name);
        return Enumerable::defer(static function () use ($element, $name): iterable {
            foreach ($element->attributes as $attribute) {
                if ($attribute instanceof DOMAttr && ($name === null || $name->matches($attribute))) {
                    yield new XAttribute($attribute);
                }
            }
        });
    }

    /** @return \Generator<int, XElement> */
    private static function descendantElements(DOMNode $parent, ?XName $name): \Generator
    {
        foreach ($parent->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if ($name === null || $name->matches($child)) {
                yield new self($child);
            }
            yield from self::descendantElements($child, $name);
        }
    }
}
