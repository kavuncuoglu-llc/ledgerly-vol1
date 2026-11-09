<?php

namespace App\Ai\Exceptions;

use RuntimeException;

final class UnknownTool extends RuntimeException
{
    public function __construct(public readonly string $toolName)
    {
        parent::__construct("No tool named [{$toolName}] is registered.");
    }
}
