<?php

declare(strict_types=1);

namespace Light\Linq\Xml;

use DOMNode;

abstract class XNode
{
    protected function __construct(protected readonly DOMNode $node)
    {
    }
}
