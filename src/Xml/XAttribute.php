<?php

declare(strict_types=1);

namespace Light\Linq\Xml;

use DOMAttr;

final class XAttribute extends XNode
{
    public function __construct(private readonly DOMAttr $attribute)
    {
        parent::__construct($attribute);
    }

    public function name(): XName
    {
        return new XName(
            $this->attribute->localName ?? $this->attribute->name,
            $this->attribute->namespaceURI ?? '',
        );
    }

    public function value(): string
    {
        return $this->attribute->value;
    }
}
