<?php

namespace App\Ai\Exceptions;

use RuntimeException;

final class UnreadableTicketSamples extends RuntimeException
{
    public static function fileMissing(string $path): self
    {
        return new self("No file at {$path}.");
    }

    public static function notALabeledList(string $path): self
    {
        return new self("{$path} must be a JSON list of objects with a message and a label.");
    }
}
