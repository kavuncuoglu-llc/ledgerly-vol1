<?php

namespace App\Ai\Encoding;

interface Format
{
    public function describe(): string;

    public function write(array $rows, string $key): string;
}
