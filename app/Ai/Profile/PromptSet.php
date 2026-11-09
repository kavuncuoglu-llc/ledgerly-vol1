<?php

namespace App\Ai\Profile;

use App\Ai\Exceptions\UnusableProfile;

final class PromptSet
{
    public static function fromFile(string $path): array
    {
        if (! is_file($path)) {
            throw UnusableProfile::missingPromptFile($path);
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw UnusableProfile::malformedPromptFile($path);
        }

        $prompts = [];

        foreach ($decoded as $entry) {
            $prompts[] = is_array($entry)
                ? (string) ($entry['prompt'] ?? '')
                : (string) $entry;
        }

        $prompts = array_values(array_filter($prompts, fn (string $prompt): bool => trim($prompt) !== ''));

        if ($prompts === []) {
            throw UnusableProfile::malformedPromptFile($path);
        }

        return $prompts;
    }
}
