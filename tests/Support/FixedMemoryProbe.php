<?php

namespace Tests\Support;

use App\Ai\Profile\MemoryProbe;
use App\Ai\Profile\MemorySample;

final class FixedMemoryProbe implements MemoryProbe
{
    public function __construct(private readonly bool $modelLoaded = false) {}

    public function read(): MemorySample
    {
        return new MemorySample(1234.5, 1_073_741_824, 2_147_483_648, 0, $this->modelLoaded);
    }

    public function residentSource(): string
    {
        return 'a fixed test value';
    }
}
