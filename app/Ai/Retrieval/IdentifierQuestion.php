<?php

namespace App\Ai\Retrieval;

final class IdentifierQuestion
{
    private const PATTERNS = [
        '/\bINV[-\s]?\d+/i',
        '/\bT-\d{3,}/i',
        '/\b(invoice|ticket|payment)\s+#?\d+/i',
        '/#\d+/',
        '/[\w.+-]+@[\w-]+\.[\w.-]+/',
        '/\b\d{4,}\b/',
    ];

    public static function in(string $question): bool
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $question) === 1) {
                return true;
            }
        }

        return false;
    }
}
