<?php

namespace App\Ai\Exceptions;

use InvalidArgumentException;

final class UnknownSamplingProfile extends InvalidArgumentException
{
    public function __construct(public readonly string $profile)
    {
        parent::__construct("Unknown sampling profile [{$profile}].");
    }
}
