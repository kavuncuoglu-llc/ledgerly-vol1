<?php

namespace App\Ai\Encoding;

use App\Ai\Exceptions\UnknownEncodingFormat;
use Illuminate\Support\Collection;

final class DataEncoder
{
    public function __construct(private readonly array $formats) {}

    public function encode(Collection $rows, Shape $shape, ?string $format = null, string $key = 'rows'): string
    {
        $writer = $this->formatNamed($format ?: (string) (config('ai.encoding.default') ?: 'toon'));

        $limited = $rows->take($shape->limit());

        $lines = [
            ...$this->contextLines($rows, $limited, $shape),
            $writer->describe(),
            $writer->write($this->shapedRows($limited, $shape), $key),
        ];

        return implode("\n", $lines);
    }

    public function formatNames(): array
    {
        return array_keys($this->formats);
    }

    private function formatNamed(string $name): Format
    {
        return $this->formats[$name] ?? throw new UnknownEncodingFormat($name, $this->formatNames());
    }

    private function shapedRows(Collection $rows, Shape $shape): array
    {
        $fields = $shape->fields();

        return $rows
            ->map(fn ($row): array => array_map(fn (callable $field): mixed => $field($row), $fields))
            ->values()
            ->all();
    }

    private function contextLines(Collection $rows, Collection $limited, Shape $shape): array
    {
        $lines = [];

        $header = (string) $shape->header($rows);

        if ($header !== '') {
            $lines[] = $header;
        }

        if ($rows->count() > $limited->count()) {
            $lines[] = "Showing {$limited->count()} of {$rows->count()} rows.";
        }

        return $lines;
    }
}
