<?php

namespace App\Ai\Exceptions;

use RuntimeException;

final class UnreadableQuestionBatch extends RuntimeException
{
    public static function fileMissing(string $path): self
    {
        return new self("Question file [{$path}] does not exist.");
    }

    public static function notAQuestionList(string $path): self
    {
        return new self("Question file [{$path}] must be a JSON object with a non-empty \"questions\" list of strings.");
    }
}
