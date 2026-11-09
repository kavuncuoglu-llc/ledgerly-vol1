<?php

namespace App\Ai\Exceptions;

use RuntimeException;
use Throwable;

final class EnforcementNotApplied extends RuntimeException
{
    public static function notJson(string $class, string $content, Throwable $previous): self
    {
        return new self(
            sprintf('Expected JSON for %s but the provider returned text that does not parse; schema enforcement is not active: %s', class_basename($class), $content),
            previous: $previous,
        );
    }

    public static function notAnObject(string $class, string $content): self
    {
        return new self(
            sprintf('Expected a JSON object for %s but the provider returned something else; schema enforcement is not active: %s', class_basename($class), $content),
        );
    }
}
