<?php

namespace App\Ai\Doctor;

use App\Ai\Hardware\OllamaClient;
use App\Ai\Native\OllamaEndpoint;

final class DoctorContext
{
    private ?array $installed = null;

    private ?RoundTripOutcome $roundTrip = null;

    private array $shows = [];

    public function __construct(
        public readonly OllamaClient $client,
        public readonly string $primaryProvider,
        public readonly array $providers,
        public readonly int $window,
        public readonly BindProbe $bind,
        public readonly AiQueueWorkers $workers,
        private readonly RoundTripProbe $probe,
        public readonly int $probeTimeoutSeconds,
        public readonly string $embeddingProvider = '',
    ) {}

    public function primaryConfig(): array
    {
        return $this->providers[$this->primaryProvider] ?? [];
    }

    public function primaryModel(): string
    {
        return (string) ($this->primaryConfig()['model'] ?? '');
    }

    public function chatProviders(): array
    {
        return array_filter(
            $this->providers,
            fn (array $config, string $name): bool => $name !== $this->embeddingProvider && ! isset($config['dimensions']),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    public function installed(): array
    {
        return $this->installed ??= $this->client->installed();
    }

    public function installedModel(string $model): array
    {
        $wanted = str_contains($model, ':')
            ? $model
            : "{$model}:latest";

        foreach ($this->installed() as $installed) {
            if (($installed['name'] ?? '') === $wanted) {
                return $installed;
            }
        }

        return [];
    }

    public function show(string $model): array
    {
        return $this->shows[$model] ??= $this->client->show($model);
    }

    public function loaded(string $model, array $running): array
    {
        $wanted = str_contains($model, ':')
            ? $model
            : "{$model}:latest";

        foreach ($running as $loaded) {
            if (($loaded['name'] ?? '') === $wanted) {
                return $loaded;
            }
        }

        return [];
    }

    public function loadedNames(array $running): string
    {
        $names = array_map(fn (array $loaded): string => (string) ($loaded['name'] ?? ''), $running);

        if ($names === []) {
            return 'nothing';
        }

        return implode(', ', $names);
    }

    public function roundTrip(): RoundTripOutcome
    {
        return $this->roundTrip ??= $this->probe->run(
            client: $this->client,
            model: $this->primaryModel(),
            nativeExtras: $this->nativeExtras(),
            timeoutSeconds: $this->probeTimeoutSeconds,
        );
    }

    public function appUsesNative(): bool
    {
        return OllamaEndpoint::usesNative($this->primaryConfig());
    }

    private function nativeExtras(): array
    {
        if (! $this->appUsesNative()) {
            return [];
        }

        return array_filter([
            'num_ctx' => config('ai.ollama.num_ctx'),
            'keep_alive' => $this->keepAliveValue(),
        ], fn (mixed $value): bool => filled($value));
    }

    private function keepAliveValue(): string|int|null
    {
        $keepAlive = $this->primaryConfig()['keep_alive'] ?? config('ai.ollama.keep_alive');

        if (blank($keepAlive)) {
            return null;
        }

        if (is_numeric($keepAlive)) {
            return (int) $keepAlive;
        }

        return (string) $keepAlive;
    }
}
