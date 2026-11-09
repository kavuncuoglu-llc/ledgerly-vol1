<?php

namespace App\Ai\Profile;

interface MemoryProbe
{
    public function read(): MemorySample;

    public function residentSource(): string;
}
