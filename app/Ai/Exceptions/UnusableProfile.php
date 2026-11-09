<?php

namespace App\Ai\Exceptions;

use RuntimeException;

final class UnusableProfile extends RuntimeException
{
    public static function missingPromptFile(string $path): self
    {
        return new self("Prompt file [{$path}] does not exist.");
    }

    public static function malformedPromptFile(string $path): self
    {
        return new self("Prompt file [{$path}] must be a JSON array of prompts (strings, or objects with a \"prompt\" key) with at least one non-empty prompt.");
    }

    public static function notChatProvider(string $provider): self
    {
        return new self("Provider [{$provider}] is not a configured chat provider with a base_url; ai:profile needs one.");
    }
}
