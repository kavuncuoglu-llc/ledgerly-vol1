<?php

namespace App\Ai\Encoding\Formats;

use App\Ai\Encoding\Format;

final class YamlFormat implements Format
{
    private const RESERVED_WORDS = [
        'true',
        'false',
        'null',
        'yes',
        'no',
        'on',
        'off',
        '~',
    ];

    public function describe(): string
    {
        return 'Data below is YAML: a list under the key, one mapping per row.';
    }

    public function write(array $rows, string $key): string
    {
        if ($rows === []) {
            return "{$key}: []";
        }

        $lines = ["{$key}:"];

        foreach ($rows as $row) {
            $lines = [...$lines, ...$this->rowLines($row)];
        }

        return implode("\n", $lines);
    }

    private function rowLines(array $row): array
    {
        $lines = [];

        foreach ($row as $field => $value) {
            $prefix = $lines === []
                ? '  - '
                : '    ';

            $lines[] = "{$prefix}{$field}: {$this->scalar($value)}";
        }

        return $lines;
    }

    private function scalar(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value
                ? 'true'
                : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return (string) $value;
        }

        return $this->string(CellText::of($value));
    }

    private function string(string $text): string
    {
        if ($this->needsQuotes($text)) {
            return (string) json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $text;
    }

    private function needsQuotes(string $text): bool
    {
        if ($text === '') {
            return true;
        }

        if (in_array(strtolower($text), self::RESERVED_WORDS, true)) {
            return true;
        }

        if (is_numeric($text)) {
            return true;
        }

        return preg_match('/^[\s\-?:,\[\]{}#&*!|>\'"%@`]|[:#]\s|\s$/', $text) === 1;
    }
}
