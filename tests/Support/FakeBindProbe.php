<?php

namespace Tests\Support;

use App\Ai\Doctor\BindProbe;

final class FakeBindProbe implements BindProbe
{
    public function __construct(
        private readonly array $localAddresses = ['127.0.0.1'],
        private readonly array $accepting = [],
        private readonly array $resolved = [],
    ) {}

    public function localAddresses(): array
    {
        return $this->localAddresses;
    }

    public function accepts(string $address, int $port): bool
    {
        return in_array($address, $this->accepting, true);
    }

    public function resolve(string $host): string
    {
        return $this->resolved[$host] ?? '';
    }
}
