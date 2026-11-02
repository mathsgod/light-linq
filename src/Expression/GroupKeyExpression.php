<?php
declare(strict_types=1);
namespace PhpLinq\Expression;
final readonly class GroupKeyExpression implements ValueExpression
{
    public function __construct(public ?string $member = null) {}
}
