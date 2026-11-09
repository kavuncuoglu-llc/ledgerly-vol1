<?php

namespace App\Ai\Exceptions;

final class RateLimited extends ModelRequestFailed
{
    public function isRetryable(): bool
    {
        return true;
    }
}
