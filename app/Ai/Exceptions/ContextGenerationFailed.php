<?php

namespace App\Ai\Exceptions;

use RuntimeException;

final class ContextGenerationFailed extends RuntimeException
{
    public static function empty(string $chunkPreview): self
    {
        return new self("The model returned no context for the chunk starting: {$chunkPreview}");
    }
}
