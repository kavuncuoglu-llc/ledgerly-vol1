<?php

namespace App\Ai\Encoding\Formats;

use App\Ai\Encoding\Format;

final class JsonFormat implements Format
{
    public function __construct(private readonly bool $pretty = false) {}

    public function describe(): string
    {
        return 'Data below is JSON.';
    }

    public function write(array $rows, string $key): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        if ($this->pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return (string) json_encode([$key => $rows], $flags);
    }
}
