<?php

namespace App\Ai\Profile;

interface MemoryProbeFactory
{
    public function make(string $provider): MemoryProbe;
}
