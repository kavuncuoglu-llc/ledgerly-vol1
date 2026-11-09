<?php

namespace App\Ai\Structured;

final class Provenance
{
    public static function missingNumbers(array $data, array $fields, string $sourceText): array
    {
        $normalizedSource = preg_replace('/[,\s]/', '', $sourceText);

        $missing = [];

        foreach ($fields as $field) {
            if (! is_numeric($data[$field] ?? null)) {
                continue;
            }

            if (str_contains($normalizedSource, number_format((float) $data[$field], 2, '.', ''))) {
                continue;
            }

            $missing[$field] = $data[$field];
        }

        return $missing;
    }
}
