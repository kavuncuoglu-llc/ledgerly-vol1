<?php

namespace App\Ai\Hardware;

interface HostInspector
{
    public function operatingSystem(): string;

    public function run(array $command): string;

    public function read(string $path): string;
}
