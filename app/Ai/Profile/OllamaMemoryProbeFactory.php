<?php

namespace App\Ai\Profile;

use App\Ai\AiManager;
use App\Ai\Hardware\HostInspector;
use App\Ai\Hardware\OllamaClient;

final class OllamaMemoryProbeFactory implements MemoryProbeFactory
{
    public function __construct(
        private readonly AiManager $ai,
        private readonly HostInspector $host,
    ) {}

    public function make(string $provider): MemoryProbe
    {
        $config = $this->ai->providerConfig($provider);

        return new OllamaMemoryProbe(
            host: $this->host,
            server: OllamaClient::forProvider($config),
            model: (string) ($config['model'] ?? ''),
        );
    }
}
