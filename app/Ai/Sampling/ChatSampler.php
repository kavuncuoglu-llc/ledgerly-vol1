<?php

namespace App\Ai\Sampling;

use App\Ai\Http\AiHttpClient;

final class ChatSampler
{
    public function __construct(
        private readonly AiHttpClient $client,
    ) {}

    public function sample(string $prompt, array $parameters = [], ?string $model = null, string $feature = 'cli.sample'): SampleResult
    {
        return $this->chat(
            messages: [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            parameters: $parameters,
            model: $model,
            feature: $feature,
        );
    }

    public function chat(array $messages, array $parameters = [], ?string $model = null, string $feature = 'cli.sample'): SampleResult
    {
        $response = $this->client->chat(
            messages: $messages,
            options: $this->options($parameters, $model),
            feature: $feature,
        );

        return new SampleResult(
            content: $response->content ?? '',
            finishReason: $response->finishReason,
            promptTokens: $response->usage->promptTokens,
            completionTokens: $response->usage->completionTokens,
        );
    }

    private function options(array $parameters, ?string $model): array
    {
        return [
            ...$parameters,
            'profile' => null,
            'model' => $model ?? config('ai.model'),
        ];
    }
}
