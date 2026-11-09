<?php

namespace App\Ai\Schema;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Describe
{
    public function __construct(public readonly string $description) {}
}
