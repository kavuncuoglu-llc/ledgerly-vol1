<?php

namespace App\Ai\Retrieval;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Exceptions\ContextGenerationFailed;
use App\Ai\Prompts\ContextualizePrompt;
use Illuminate\Support\Str;

final class ContextualizeChunks
{
    public function __construct(
        private readonly AiManager $ai,
        private readonly ?string $model = null,
    ) {}

    public function contextFor(string $articleBody, string $chunkText): string
    {
        $request = ChatRequest::fromOptions(
            messages: $this->messages($articleBody, $chunkText),
            options: [...$this->options(), 'cache_breakpoints_after' => [0]],
            feature: 'retrieval.context',
            promptVersion: ContextualizePrompt::VERSION,
        );

        $reply = $this->ai->for('retrieval.context')->chat($request);

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
        return [
            'role' => 'system',
            'content' => ContextualizePrompt::document($articleBody),
        ];
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
