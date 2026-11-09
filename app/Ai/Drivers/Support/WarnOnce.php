<?php

namespace App\Ai\Drivers\Support;

use Illuminate\Support\Facades\Log;

final class WarnOnce
{
    private static array $seen = [];

    public static function droppedOption(string $provider, string $option): void
    {
        $key = "{$provider}:{$option}";

        if (isset(self::$seen[$key])) {
            return;
        }

        self::$seen[$key] = true;

        Log::warning('Provider does not support a requested option; it was dropped', [
            'provider' => $provider,
            'option' => $option,
        ]);
    }

    public static function reset(): void
    {
        self::$seen = [];
    }
}
