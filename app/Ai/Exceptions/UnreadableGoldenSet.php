<?php

namespace App\Ai\Exceptions;

use RuntimeException;

final class UnreadableGoldenSet extends RuntimeException
{
    public static function missing(string $path): self
    {
        return new self("The golden set {$path} does not exist.");
    }

    public static function empty(string $path): self
    {
        return new self("The golden set {$path} has no questions.");
    }

    public static function invalidLine(string $path, int $lineNumber): self
    {
        return new self("Line {$lineNumber} of {$path} is not a JSON object with id, question and chunks.");
    }
}
