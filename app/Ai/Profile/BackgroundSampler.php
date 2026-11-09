<?php

namespace App\Ai\Profile;

interface BackgroundSampler
{
    public function start(string $provider, float $interval): void;

    public function stop(): array;
}
