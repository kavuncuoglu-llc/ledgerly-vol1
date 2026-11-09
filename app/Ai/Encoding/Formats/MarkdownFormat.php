<?php

namespace App\Ai\Encoding\Formats;

use App\Ai\Encoding\Format;

final class MarkdownFormat implements Format
{
    public function describe(): string
    {
        return 'Data below is a Markdown table: a header row, a separator row, then one row per line.';
    }

    public function write(array $rows, string $key): string
    {
        if ($rows === []) {
            return '';
        }

        $fields = array_keys($rows[0]);

        $lines = [
            $this->line($fields),
            $this->line(array_fill(0, count($fields), '---')),
        ];

        foreach ($rows as $row) {
            $lines[] = $this->line(array_map(fn (mixed $value): string => CellText::of($value), $row));
        }

        return implode("\n", $lines);
    }

    private function line(array $cells): string
    {
        $escaped = array_map(fn (string $cell): string => str_replace('|', '\\|', $cell), $cells);

        return '| '.implode(' | ', $escaped).' |';
    }
}
