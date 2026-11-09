<?php

namespace App\Ai\Exceptions;

use InvalidArgumentException;

final class UnsupportedTokenCounter extends InvalidArgumentException
{
    public function __construct(public readonly string $driver)
    {
        parent::__construct("Token counter [{$driver}] is not available. Use ratio, tiktoken or llama-server.");
    }
}
