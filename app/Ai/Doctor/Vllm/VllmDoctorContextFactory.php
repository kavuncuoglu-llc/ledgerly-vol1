<?php

namespace App\Ai\Doctor\Vllm;

use App\Ai\AiManager;
use App\Ai\Doctor\AiQueueWorkers;
use App\Ai\Native\VllmEndpoint;
use App\Ai\Vllm\VllmServerClient;

final class VllmDoctorContextFactory
{
    public function __construct(
        private readonly AiManager $ai,
        private readonly AiQueueWorkers $workers,
    ) {}

    public function make(string $provider, int $window): VllmDoctorContext
    {
        $config = $this->ai->providerConfig($provider);

        return new VllmDoctorContext(
            client: VllmServerClient::forProvider($config),
            providerName: $provider,
            providerConfig: $config,
            window: $window,
            workers: $this->workers,
            probeTimeoutSeconds: (int) config('ai.vllm.probe_timeout'),
        );
    }

    public function isVllm(string $provider): bool
    {
        return VllmEndpoint::isVllm($this->ai->providerConfig($provider));
    }
}
