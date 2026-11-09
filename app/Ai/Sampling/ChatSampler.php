<?php

namespace App\Ai\Sampling;

use App\Ai\Exceptions\ModelRequestFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class ChatSampler
{
    public function sample(string $prompt, array $parameters = [], ?string $model = null): SampleResult
    {
        $response = $this->post([
            ...$parameters,
            'model' => $model ?? config('ai.model'),
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'stream' => false,
        ]);

        return $this->toResult($response);
    }

    private function post(array $body): Response
    {
        try {
            $response = $this->http()->post('/chat/completions', $body);
        } catch (ConnectionException $exception) {
            throw ModelRequestFailed::unreachable(config('ai.base_url'), $exception);
        }

        if ($response->failed()) {
            throw ModelRequestFailed::rejected($response->status(), $response->body());
        }

        return $response;
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

    private function http(): PendingRequest
    {
        return Http::baseUrl(config('ai.base_url'))
            ->withToken(config('ai.api_key'))
            ->acceptJson()
            ->connectTimeout(config('ai.connect_timeout'))
            ->timeout(config('ai.timeout'));
    }
}
