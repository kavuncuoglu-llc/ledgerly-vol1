<?php

namespace App\Ai\Encoding\Formats;

use App\Ai\Encoding\Format;

final class TsvFormat implements Format
{
    public function describe(): string
    {
        return 'Data below is tab-separated: the first line holds the field names, then one row per line.';
    }

    public function write(array $rows, string $key): string
    {
        if ($rows === []) {
            return '';
        }

        $lines = [implode("\t", array_keys($rows[0]))];

        foreach ($rows as $row) {
            $lines[] = implode("\t", array_map(fn (mixed $value): string => CellText::of($value), $row));
        }

        return implode("\n", $lines);
    }
}
