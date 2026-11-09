<?php

namespace App\Ai\Exceptions;

use RuntimeException;

final class EmbeddingFailed extends RuntimeException
{
    public static function malformed(): self
    {
        return new self('The model server answered with a body that has no data[0].embedding.');
    }

    public static function wrongDimensions(string $model, int $expected, int $actual): self
    {
        return new self("Model {$model} returned {$actual} dimensions but AI_EMBED_DIMENSIONS and the help_chunks column expect {$expected}.");
    }
}
