<?php

namespace App\Ai\Exceptions;

use RuntimeException;

final class DuplicateTool extends RuntimeException
{
    public function __construct(public readonly string $toolName)
    {
        parent::__construct("A tool named [{$toolName}] is already registered.");
    }
}
