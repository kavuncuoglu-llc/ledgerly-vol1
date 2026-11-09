<?php

namespace App\Ai\Exceptions;

use RuntimeException;

final class UnscriptedModelCall extends RuntimeException
{
    public static function for(string $provider, string $feature): self
    {
        return new self("Ai::fake() has no scripted response left for feature [{$feature}] on provider [{$provider}].");
    }
}
