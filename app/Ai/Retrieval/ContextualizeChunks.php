<?php

namespace App\Ai\Retrieval;

use App\Ai\Context\ProviderCapabilities;
use App\Ai\Exceptions\ContextGenerationFailed;
use App\Ai\Http\AiHttpClient;
use App\Ai\Prompts\ContextualizePrompt;
use Illuminate\Support\Str;

final class ContextualizeChunks
{
    public function __construct(
        private readonly AiHttpClient $client,
        private readonly ProviderCapabilities $capabilities,
        private readonly ?string $model = null,
    ) {}

    public function contextFor(string $articleBody, string $chunkText): string
    {
        $reply = $this->client->chat(
            messages: $this->messages($articleBody, $chunkText),
            options: $this->options(),
            feature: 'retrieval.context',
            promptVersion: ContextualizePrompt::VERSION,
        );

        $context = trim((string) preg_replace('/\s+/u', ' ', (string) $reply->content));

        if ($context === '') {
            throw ContextGenerationFailed::empty(Str::limit($chunkText, 60));
        }

        return $context;
    }

    private function messages(string $articleBody, string $chunkText): array
    {
        return [
            $this->documentMessage($articleBody),
            [
                'role' => 'user',
                'content' => ContextualizePrompt::chunkRequest($chunkText),
            ],
        ];
    }

    private function documentMessage(string $articleBody): array
    {
        $message = [
            'role' => 'system',
            'content' => ContextualizePrompt::document($articleBody),
        ];

        if (! $this->capabilities->supportsCacheBreakpoints()) {
            return $message;
        }

        $message['cache_control'] = [
            'type' => 'ephemeral',
        ];

        return $message;
    }

    private function options(): array
    {
        $options = [
            'profile' => 'deterministic',
            'max_tokens' => 150,
        ];

        if ($this->model === null) {
            return $options;
        }

        return [...$options, 'model' => $this->model];
    }
}
