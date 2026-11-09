<?php

namespace App\Ai\Exceptions;

use InvalidArgumentException;

final class UnsupportedReranker extends InvalidArgumentException
{
    public function __construct(string $driver)
    {
        parent::__construct("Unknown reranker driver [{$driver}]. Use sidecar, lexical or none.");
    }
}
