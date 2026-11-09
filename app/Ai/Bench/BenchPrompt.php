<?php

namespace App\Ai\Bench;

final class BenchPrompt
{
    private const SYSTEM = 'You answer questions about the data you are given. Use only that data. Reply with only the answer (an invoice number, a date, a status, or a number) and nothing else.';

    public static function messages(string $data, string $question): array
    {
        return [
            [
                'role' => 'system',
                'content' => self::SYSTEM,
            ],
            [
                'role' => 'user',
                'content' => "{$data}\n\nQuestion: {$question}",
            ],
        ];
    }

    public static function matches(string $answer, string $expected): bool
    {
        if (is_numeric($expected)) {
            return self::containsNumber($answer, (float) $expected);
        }

        $pattern = '/(?<![A-Za-z0-9-])'.preg_quote($expected, '/').'(?![A-Za-z0-9])/i';

        return preg_match($pattern, $answer) === 1;
    }

    private static function containsNumber(string $answer, float $expected): bool
    {
        $cleaned = (string) preg_replace('/\d{4}-\d{2}-\d{2}|INV-\d+/i', ' ', $answer);

        $cleaned = (string) preg_replace('/(?<=\d),(?=\d{3})/', '', $cleaned);

        preg_match_all('/\d+(?:\.\d+)?/', $cleaned, $found);

        foreach ($found[0] as $candidate) {
            if (abs((float) $candidate - $expected) < 0.005) {
                return true;
            }
        }

        return false;
    }
}
