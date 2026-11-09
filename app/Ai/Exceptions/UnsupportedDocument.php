<?php

namespace App\Ai\Exceptions;

use RuntimeException;

final class UnsupportedDocument extends RuntimeException
{
    public static function extension(string $path, string $extension): self
    {
        return new self("No parser is registered for .{$extension} files ({$path}).");
    }

    public static function unreadable(string $path, string $reason): self
    {
        return new self("Could not parse {$path}: {$reason}");
    }
}
