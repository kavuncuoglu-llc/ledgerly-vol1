<?php

namespace App\Ai\Encoding;

use Illuminate\Support\Collection;

interface Shape
{
    public function fields(): array;

    public function header(Collection $rows): ?string;

    public function limit(): int;
}
