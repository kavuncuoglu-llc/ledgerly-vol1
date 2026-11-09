<?php

namespace App\Ai\Tiers;

enum ModelTier: string
{
    case Small = 'small';
    case Mid = 'mid';
    case Frontier = 'frontier';

    public function rank(): int
    {
        return match ($this) {
            self::Small => 0,
            self::Mid => 1,
            self::Frontier => 2,
        };
    }

    public function below(): ?self
    {
        return match ($this) {
            self::Small => null,
            self::Mid => self::Small,
            self::Frontier => self::Mid,
        };
    }
}
