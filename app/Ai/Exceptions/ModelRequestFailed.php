<?php

namespace App\Ai\Exceptions;

use RuntimeException;
use Throwable;

final class ModelRequestFailed extends RuntimeException
{
    public static function unreachable(string $baseUrl, Throwable $previous): self
    {
        return new self("Could not reach the model server at {$baseUrl}. Is it running?", previous: $previous);
    }

    public static function rejected(int $status, string $body): self
    {
        return new self("The model server answered HTTP {$status}: {$body}");
    }

    public static function malformed(): self
    {
        return new self('The model server answered with a body that has no choices[0].message.content.');
    }
}
