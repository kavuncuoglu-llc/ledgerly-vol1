<?php

namespace App\Ai\Exceptions;

use RuntimeException;

final class CacheScopeRequired extends RuntimeException
{
    public static function forStore(): self
    {
        return new self('A cached answer needs a tenant. Only a human reviewer may share one across tenants.');
    }
}
