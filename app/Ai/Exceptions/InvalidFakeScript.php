<?php

namespace App\Ai\Exceptions;

use RuntimeException;

final class InvalidFakeScript extends RuntimeException
{
    public static function failsSchema(string $feature, string $detail): self
    {
        return new self("The scripted response for [{$feature}] does not satisfy the request's response_format: {$detail}");
    }
}
