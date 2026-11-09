<?php

namespace App\Ai\Retrieval;

final class LabelMap
{
    public static function for(array $chunks): array
    {
        $map = [];

        foreach ($chunks as $chunk) {
            $map[self::uniqueLabel($chunk, $map)] = $chunk;
        }

        return $map;
    }

    private static function uniqueLabel(RetrievedChunk $chunk, array $taken): string
    {
        $label = $chunk->label();

        $attempt = 2;

        while (isset($taken[$label])) {
            $label = $chunk->label()." ({$attempt})";

            $attempt++;
        }

        return $label;
    }
}
