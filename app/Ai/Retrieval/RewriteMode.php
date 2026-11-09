<?php

namespace App\Ai\Retrieval;

enum RewriteMode: string
{
    case Off = 'off';
    case Rewrite = 'rewrite';
    case Expand = 'expand';
    case Hyde = 'hyde';

    public function maxTokens(): int
    {
        return match ($this) {
            self::Off => 0,
            self::Rewrite => 60,
            self::Expand => 120,
            self::Hyde => 160,
        };
    }
}
