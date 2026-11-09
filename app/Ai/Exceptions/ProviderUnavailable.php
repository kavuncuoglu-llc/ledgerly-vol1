<?php

namespace App\Ai\Exceptions;

final class ProviderUnavailable extends ModelRequestFailed
{
    public function isRetryable(): bool
    {
        return true;
    }
}
