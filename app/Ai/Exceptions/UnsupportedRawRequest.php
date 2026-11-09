<?php

namespace App\Ai\Exceptions;

use RuntimeException;

final class UnsupportedRawRequest extends RuntimeException
{
    public static function for(string $provider): self
    {
        return new self("Provider [{$provider}] has no provider-shaped API to send a raw request to.");
    }
}
