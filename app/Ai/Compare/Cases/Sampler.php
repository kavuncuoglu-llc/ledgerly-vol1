<?php

namespace App\Ai\Compare\Cases;

final class Sampler
{
    public static function spread(array $items, int $limit): array
    {
        $items = array_values($items);

        if ($limit <= 0) {
            return $items;
        }

        if ($limit >= count($items)) {
            return $items;
        }

        $step = count($items) / $limit;

        $picked = [];

        for ($index = 0; $index < $limit; $index++) {
            $position = (int) floor($index * $step);

            $picked[$position] = $items[$position];
        }

        return $picked;
    }
}
