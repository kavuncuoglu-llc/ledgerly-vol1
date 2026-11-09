<?php

namespace App\Ai\Doctor\Llama;

use App\Ai\AiManager;
use App\Ai\Doctor\AiQueueWorkers;
use App\Ai\Llama\LlamaServerClient;
use App\Ai\Native\LlamaEndpoint;

final class LlamaDoctorContextFactory
{
    public function __construct(
        private readonly AiManager $ai,
        private readonly AiQueueWorkers $workers,
    ) {}

    public function make(string $provider, int $window): LlamaDoctorContext
    {
        $config = $this->ai->providerConfig($provider);

        return new LlamaDoctorContext(
            client: LlamaServerClient::forProvider($config),
            providerName: $provider,
            providerConfig: $config,
            window: $window,
            workers: $this->workers,
            probeTimeoutSeconds: (int) config('ai.llama.probe_timeout'),
        );
    }

    public function isLlamaServer(string $provider): bool
    {
        return LlamaEndpoint::isLlamaServer($this->ai->providerConfig($provider));
    }
}
