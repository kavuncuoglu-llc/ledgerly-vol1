<?php

namespace App\Ai\Exceptions;

use RuntimeException;

final class UnreadableReplayScript extends RuntimeException
{
    public static function fileMissing(string $path): self
    {
        return new self("Replay script [{$path}] does not exist.");
    }

    public static function notAMessageList(string $path): self
    {
        return new self("Replay script [{$path}] must be JSON with a non-empty \"messages\" list of strings.");
    }
}
