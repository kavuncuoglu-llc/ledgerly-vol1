<?php

namespace App\Ai\Doctor;

use App\Ai\AiManager;
use App\Ai\Exceptions\UnknownProvider;
use App\Ai\Hardware\OllamaClient;
use App\Ai\Native\OllamaEndpoint;

final class DoctorContextFactory
{
    public function __construct(
        private readonly AiManager $ai,
        private readonly BindProbe $bind,
        private readonly AiQueueWorkers $workers,
        private readonly RoundTripProbe $probe,
    ) {}

    public function make(string $primaryProvider, int $window): DoctorContext
    {
        $primary = $this->ai->providerConfig($primaryProvider);

        $client = OllamaClient::forProvider($primary);

        return new DoctorContext(
            client: $client,
            primaryProvider: $primaryProvider,
            providers: $this->providersOn($client, $primaryProvider, $primary),
            window: $window,
            bind: $this->bind,
            workers: $this->workers,
            probe: $this->probe,
            probeTimeoutSeconds: (int) config('ai.ollama.probe_timeout'),
            embeddingProvider: (string) config('ai.embedding.provider'),
        );
    }

    public function isOllama(string $provider): bool
    {
        return OllamaEndpoint::isOllama($this->ai->providerConfig($provider));
    }

    private function providersOn(OllamaClient $client, string $primaryProvider, array $primary): array
    {
        $providers = [$primaryProvider => $primary];

        foreach ((array) config('ai.ollama.providers') as $name) {
            $config = $this->configOf((string) $name);

            if ($config === []) {
                continue;
            }

            if (OllamaEndpoint::nativeBaseUrl((string) ($config['base_url'] ?? '')) !== $client->baseUrl()) {
                continue;
            }

            if (blank($config['model'] ?? null)) {
                continue;
            }

            $providers[(string) $name] ??= $config;
        }

        return $providers;
    }

    private function configOf(string $name): array
    {
        try {
            return $this->ai->providerConfig($name);
        } catch (UnknownProvider) {
            return [];
        }
    }
}
