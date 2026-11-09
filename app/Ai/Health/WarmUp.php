<?php

namespace App\Ai\Health;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Native\ServerReply;
use App\Ai\Prompts\SupportPrompt;
use Illuminate\Support\Sleep;

final class WarmUp
{
    private const QUESTION = 'How do I record a payment against an invoice?';

    private const MAX_TOKENS = 8;

    private const POLL_SECONDS = 5;

    public function __construct(
        private readonly AiManager $ai,
        private readonly WarmRecord $record,
    ) {}

    public function models(string $provider): array
    {
        $models = [(string) $this->ai->providerConfig($provider)['model']];

        foreach ((array) config('ai.features', []) as $feature) {
            if (($feature['provider'] ?? null) !== $provider) {
                continue;
            }

            if (blank($feature['model'] ?? null)) {
                continue;
            }

            $models[] = (string) $feature['model'];
        }

        return array_values(array_unique($models));
    }

    public function waitUntilReady(string $provider, int $seconds): ServerReply
    {
        $probe = ServerProbe::forProvider($this->ai->providerConfig($provider));

        $waited = 0;

        while (true) {
            $reply = $probe->models(5);

            if ($reply->ok()) {
                return $reply;
            }

            if ($waited >= $seconds) {
                return $reply;
            }

            Sleep::sleep(self::POLL_SECONDS);

            $waited += self::POLL_SECONDS;
        }
    }

    public function run(string $provider, string $model): WarmResult
    {
        $chat = $this->ai->chatWithoutFailover($provider);

        $firstStarted = hrtime(true);

        $chat->chat($this->request($model));

        $firstSeconds = $this->secondsSince($firstStarted);

        $secondStarted = hrtime(true);

        $response = $chat->chat($this->request($model));

        return new WarmResult(
            model: $model,
            firstSeconds: $firstSeconds,
            warmSeconds: $this->secondsSince($secondStarted),
            promptTokens: $response->usage->promptTokens,
            cachedTokens: $response->usage->cachedTokens,
        );
    }

    public function remember(string $provider, array $models, float $seconds): void
    {
        $this->record->remember($provider, $models, $seconds);
    }

    private function request(string $model): ChatRequest
    {
        return ChatRequest::fromOptions(
            messages: [
                [
                    'role' => 'system',
                    'content' => SupportPrompt::system(),
                ],
                [
                    'role' => 'user',
                    'content' => self::QUESTION,
                ],
            ],
            options: [
                'profile' => 'deterministic',
                'model' => $model,
                'max_tokens' => self::MAX_TOKENS,
            ],
            feature: 'ai.warm',
        );
    }

    private function secondsSince(int|float $started): float
    {
        return (hrtime(true) - $started) / 1_000_000_000;
    }
}
