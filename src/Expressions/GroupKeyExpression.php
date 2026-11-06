<?php
declare(strict_types=1);
namespace Light\Linq\Expressions;
final readonly class GroupKeyExpression implements ValueExpression
{
    public function __construct(public ?string $member = null) {}
}
