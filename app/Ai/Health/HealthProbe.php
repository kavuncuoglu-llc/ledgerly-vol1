<?php

namespace App\Ai\Health;

use App\Ai\AiManager;
use App\Ai\Native\ServerReply;
use App\Ai\Resilience\CircuitBreaker;

final class HealthProbe
{
    private const PROBE_PROMPT = 'Reply with the single word: ok';

    private const COMPATIBLE_DRIVERS = [
        'openai-compatible',
        'ollama',
        'llama-server',
        'vllm',
        'triton',
    ];

    public function __construct(
        private readonly AiManager $ai,
        private readonly CircuitBreaker $breaker,
        private readonly WarmRecord $warmRecord,
    ) {}

    public function supports(string $provider): bool
    {
        $driver = (string) ($this->ai->providerConfig($provider)['driver'] ?? '');

        return in_array($driver, self::COMPATIBLE_DRIVERS, true);
    }

    public function check(string $provider): HealthReport
    {
        $config = $this->ai->providerConfig($provider);

        $timeout = (int) config('ai.health.probe_timeout');

        $probe = ServerProbe::forProvider($config);

        $models = $probe->models($timeout);

        $breaker = $this->breaker->state($provider);

        $lastWarmUp = $this->warmRecord->last($provider);

        if (! $models->reachable()) {
            return new HealthReport($provider, false, $models->errorMessage(), false, 'not tried', false, 'not tried', $breaker, $lastWarmUp);
        }

        $completion = $probe->completion($this->tinyCompletion($config), $timeout);

        if (! $this->completed($completion)) {
            return new HealthReport($provider, true, $this->reachableDetail($models), false, $completion->errorMessage(), false, 'not tried', $breaker, $lastWarmUp);
        }

        $warm = $completion->milliseconds <= (int) config('ai.health.warm_ms');

        return new HealthReport(
            provider: $provider,
            reachable: true,
            reachableDetail: $this->reachableDetail($models),
            loaded: true,
            loadedDetail: $this->loadedDetail($completion),
            warm: $warm,
            warmDetail: $this->warmDetail($completion, $warm),
            breaker: $breaker,
            lastWarmUp: $lastWarmUp,
        );
    }

    private function tinyCompletion(array $config): array
    {
        return [
            'model' => (string) ($config['model'] ?? ''),
            'messages' => [
                [
                    'role' => 'user',
                    'content' => self::PROBE_PROMPT,
                ],
            ],
            'max_tokens' => 1,
            'temperature' => 0,
            'stream' => false,
        ];
    }

    private function completed(ServerReply $reply): bool
    {
        if (! $reply->ok()) {
            return false;
        }

        return isset($reply->body['choices'][0]);
    }

    private function reachableDetail(ServerReply $models): string
    {
        if ($models->ok()) {
            return "/models answered in {$models->milliseconds} ms";
        }

        return "/models answered {$models->errorMessage()}";
    }

    private function loadedDetail(ServerReply $completion): string
    {
        $tokens = (int) ($completion->body['usage']['prompt_tokens'] ?? 0);

        if ($tokens === 0) {
            return 'a one-token completion succeeded';
        }

        return "a one-token completion succeeded ({$tokens} prompt tokens)";
    }

    private function warmDetail(ServerReply $completion, bool $warm): string
    {
        $seconds = number_format($completion->milliseconds / 1000, 1);

        if ($warm) {
            return "first completion {$seconds} s";
        }

        return "first completion {$seconds} s, over the ".number_format((int) config('ai.health.warm_ms') / 1000, 1).' s threshold';
    }
}
