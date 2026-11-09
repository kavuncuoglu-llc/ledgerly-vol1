<?php

namespace App\Ai\Sampling;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;

final class ChatSampler
{
    public function __construct(
        private readonly AiManager $ai,
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
        $response = $this->ai->for($feature)->chat(new ChatRequest(
            messages: $messages,
            feature: $feature,
            model: $model,
            options: $parameters,
            profile: ChatRequest::NO_PROFILE,
        ));

        return new SampleResult(
            content: $response->content ?? '',
            finishReason: $response->finishReason->value,
            promptTokens: $response->usage->promptTokens,
            completionTokens: $response->usage->completionTokens,
        );
    }
}
