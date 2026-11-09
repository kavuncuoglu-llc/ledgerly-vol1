<?php

namespace App\Ai\Load;

final class Percentile
{
    public static function of(array $values, float $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);

        $rank = (int) ceil(($percentile / 100) * count($values));

        return (float) $values[max(0, $rank - 1)];
    }
}
