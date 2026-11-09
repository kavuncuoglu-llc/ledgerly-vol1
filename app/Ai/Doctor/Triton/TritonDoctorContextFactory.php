<?php

namespace App\Ai\Doctor\Triton;

use App\Ai\AiManager;
use App\Ai\Native\TritonEndpoint;
use App\Ai\Triton\TritonServerClient;

final class TritonDoctorContextFactory
{
    public function __construct(private readonly AiManager $ai) {}

    public function make(string $provider, int $window): TritonDoctorContext
    {
        $config = $this->ai->providerConfig($provider);

        return new TritonDoctorContext(
            client: TritonServerClient::forProvider($config),
            kserve: TritonServerClient::kserveForProvider($config),
            providerName: $provider,
            providerConfig: $config,
            window: $window,
            probeTimeoutSeconds: (int) config('ai.triton.probe_timeout'),
        );
    }

    public function isTriton(string $provider): bool
    {
        return TritonEndpoint::isTriton($this->ai->providerConfig($provider));
    }
}
