<?php

namespace Tests\Support;

use App\Ai\Profile\MemoryProbe;
use App\Ai\Profile\MemoryProbeFactory;

final class FixedMemoryProbeFactory implements MemoryProbeFactory
{
    public function __construct(private readonly MemoryProbe $probe) {}

    public function make(string $provider): MemoryProbe
    {
        return $this->probe;
    }
}
