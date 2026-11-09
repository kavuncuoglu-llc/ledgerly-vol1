<?php

namespace App\Ai\Tiers;

use App\Ai\AiManager;

final class ModelTiers
{
    public function __construct(private readonly AiManager $ai) {}

    public function providerFor(ModelTier $tier): string
    {
        $configured = $this->providerNamed($tier);

        if ($this->isUsable($configured)) {
            return $configured;
        }

        $lower = $tier->below();

        if ($lower === null) {
            return $configured;
        }

        return $this->providerFor($lower);
    }

    public function isServed(ModelTier $tier): bool
    {
        return $this->providerFor($tier) === $this->providerNamed($tier);
    }

    public function providerNamed(ModelTier $tier): string
    {
        return (string) (config('ai.tiers', [])[$tier->value] ?? '');
    }

    private function isUsable(string $provider): bool
    {
        if ($provider === '') {
            return false;
        }

        return $this->ai->isConfigured($provider);
    }
}
