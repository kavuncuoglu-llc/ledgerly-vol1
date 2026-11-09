<?php

namespace App\Ai\Tokens;

final class RatioCounter implements TokenCounter
{
    public function __construct(private float $charsPerToken = 3.6) {}

    public function count(string $text): int
    {
        return (int) ceil(mb_strlen($text) / $this->charsPerToken);
    }
}
