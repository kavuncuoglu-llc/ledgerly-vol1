<?php

namespace App\Ai\Encoding\Formats;

use App\Ai\Encoding\Format;
use HelgeSverre\Toon\EncodeOptions;
use HelgeSverre\Toon\Toon;

final class ToonFormat implements Format
{
    public function __construct(private readonly string $delimiter = ',') {}

    public function describe(): string
    {
        return match ($this->delimiter) {
            "\t" => 'Data below is in TOON format: `key[count]{fields}:` then one tab-separated row per line.',
            '|' => 'Data below is in TOON format: `key[count]{fields}:` then one pipe-separated row per line.',
            default => 'Data below is in TOON format: `key[count]{fields}:` then one comma-separated row per line.',
        };
    }

    public function write(array $rows, string $key): string
    {
        return Toon::encode(
            [$key => $rows],
            new EncodeOptions(delimiter: $this->delimiter),
        );
    }
}
