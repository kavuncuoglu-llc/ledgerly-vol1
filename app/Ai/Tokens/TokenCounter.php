<?php

namespace App\Ai\Tokens;

interface TokenCounter
{
    public function count(string $text): int;
}
