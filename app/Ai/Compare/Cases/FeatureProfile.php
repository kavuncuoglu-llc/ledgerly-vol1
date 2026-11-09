<?php

namespace App\Ai\Compare\Cases;

final class FeatureProfile
{
    public static function of(string $feature): ?string
    {
        return config('ai.features', [])[$feature]['profile'] ?? null;
    }
}
