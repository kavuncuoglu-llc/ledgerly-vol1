<?php

namespace App\Ai\Doctor;

interface BindProbe
{
    public function localAddresses(): array;

    public function accepts(string $address, int $port): bool;

    public function resolve(string $host): string;
}
