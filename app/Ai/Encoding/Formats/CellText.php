<?php

namespace App\Ai\Encoding\Formats;

final class CellText
{
    public static function of(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value
                ? 'true'
                : 'false';
        }

        if (is_array($value)) {
            return self::collapse((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return self::collapse((string) $value);
    }

    private static function collapse(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
