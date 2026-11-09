<?php

namespace App\Ai\Sampling;

use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Http\ModelEndpoint;
use Illuminate\Http\Client\Response;

final class ChatSampler
{
    public function __construct(
        private readonly ModelEndpoint $endpoint,
    ) {}

    public function sample(string $prompt, array $parameters = [], ?string $model = null): SampleResult
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
        );
    }

    public function chat(array $messages, array $parameters = [], ?string $model = null): SampleResult
    {
        $response = $this->endpoint->post('/chat/completions', [
            ...$parameters,
            'model' => $model ?? config('ai.model'),
            'messages' => $messages,
            'stream' => false,
        ]);

        return $this->toResult($response);
    }

    private function toResult(Response $response): SampleResult
    {
        $choice = $response->json('choices.0');

        if (! isset($choice['message']['content'])) {
            throw ModelRequestFailed::malformed();
        }

        return new SampleResult(
            content: $choice['message']['content'],
            finishReason: $choice['finish_reason'] ?? 'unknown',
            promptTokens: (int) $response->json('usage.prompt_tokens', 0),
            completionTokens: (int) $response->json('usage.completion_tokens', 0),
        );
    }
}
