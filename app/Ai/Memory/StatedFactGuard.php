<?php

namespace App\Ai\Memory;

use Illuminate\Support\Str;

final class StatedFactGuard
{
    private const MINIMUM_WORD_LENGTH = 3;

    private const REQUIRED_SHARE = 0.5;

    private const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    public function allows(string $key, string $value, string $customerText): bool
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            return false;
        }

        $words = $this->contentWords($value);

        if ($words === []) {
            return false;
        }

        $haystack = Str::lower($customerText);

        $found = array_filter($words, fn (string $word): bool => str_contains($haystack, $word));

        return count($found) / count($words) >= self::REQUIRED_SHARE;
    }

    private function contentWords(string $value): array
    {
        preg_match_all('/[\p{L}\p{N}@._-]+/u', Str::lower($value), $matches);

        return array_values(array_filter(
            array_map(fn (string $word): string => trim($word, '.,_-'), $matches[0]),
            fn (string $word): bool => mb_strlen($word) >= self::MINIMUM_WORD_LENGTH,
        ));
    }
}
