<?php

namespace App\Ai\Stats;

use App\Ai\Exceptions\InvalidStatsWindow;
use Carbon\CarbonImmutable;

final class StatsWindow
{
    public static function since(string $window): CarbonImmutable
    {
        if (preg_match('/^(\d+)([mhd])$/', trim($window), $parts) !== 1) {
            throw new InvalidStatsWindow($window);
        }

        $amount = (int) $parts[1];

        if ($amount < 1) {
            throw new InvalidStatsWindow($window);
        }

        return match ($parts[2]) {
            'm' => CarbonImmutable::now()->subMinutes($amount),
            'h' => CarbonImmutable::now()->subHours($amount),
            'd' => CarbonImmutable::now()->subDays($amount),
        };
    }
}
