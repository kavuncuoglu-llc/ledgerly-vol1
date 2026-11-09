<?php

namespace App\Ai\Schema;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class ItemsOf
{
    public function __construct(public readonly string $class) {}
}
