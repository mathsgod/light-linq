<?php

declare(strict_types=1);

namespace Light\Linq\Xml;

use DOMNode;

final readonly class XName
{
    public function __construct(
        public string $localName,
        public string $namespaceName = '',
    ) {
        if ($localName === '') {
            throw new \InvalidArgumentException('An XML local name cannot be empty.');
        }
    }

    public static function from(self|string $name): self
    {
        if ($name instanceof self) {
            return $name;
        }
        if (str_starts_with($name, '{')) {
            $end = strpos($name, '}');
            if ($end === false || $end === 1 || $end === strlen($name) - 1) {
                throw new \InvalidArgumentException("Invalid expanded XML name: {$name}");
            }
            return new self(substr($name, $end + 1), substr($name, 1, $end - 1));
        }
        return new self($name);
    }

    public function matches(DOMNode $node): bool
    {
        $localName = $node->localName ?? $node->nodeName;
        return $localName === $this->localName
            && ($node->namespaceURI ?? '') === $this->namespaceName;
    }

    public function __toString(): string
    {
        return $this->namespaceName === ''
            ? $this->localName
            : '{'.$this->namespaceName.'}'.$this->localName;
    }
}
