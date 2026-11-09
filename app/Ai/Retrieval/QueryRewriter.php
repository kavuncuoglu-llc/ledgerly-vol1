<?php

namespace App\Ai\Retrieval;

use App\Ai\Http\AiHttpClient;
use App\Ai\Prompts\RewritePrompt;

final class QueryRewriter
{
    public function __construct(
        private readonly AiHttpClient $client,
        private readonly RewriteMode $mode,
        private readonly ?string $model = null,
    ) {}

    public function mode(): RewriteMode
    {
        return $this->mode;
    }

    public function queries(string $question): array
    {
        if ($this->skips($question)) {
            return [$question];
        }

        return $this->withOriginalFirst($question, $this->variants($question));
    }

    public function skips(string $question): bool
    {
        if ($this->mode === RewriteMode::Off) {
            return true;
        }

        return IdentifierQuestion::in($question);
    }

    private function variants(string $question): array
    {
        $options = [
            'profile' => 'deterministic',
            'max_tokens' => $this->mode->maxTokens(),
        ];

        if ($this->model !== null) {
            $options['model'] = $this->model;
        }

        $reply = $this->client->chat(
            messages: RewritePrompt::messages($this->mode, $question),
            options: $options,
            feature: 'retrieval.rewrite',
            promptVersion: RewritePrompt::VERSION,
        );

        return match ($this->mode) {
            RewriteMode::Expand => $this->listFrom((string) $reply->content),
            default => [$this->singleLine((string) $reply->content)],
        };
    }

    private function withOriginalFirst(string $question, array $variants): array
    {
        $kept = array_filter(
            $variants,
            fn (string $variant): bool => $variant !== '' && mb_strtolower($variant) !== mb_strtolower($question),
        );

        return [$question, ...array_values(array_unique($kept))];
    }

    private function listFrom(string $reply): array
    {
        $decoded = json_decode($this->unfenced($reply), true);

        if (is_array($decoded)) {
            return array_map(fn (mixed $item): string => $this->singleLine((string) $item), array_values($decoded));
        }

        $lines = preg_split('/\R/', $reply, -1, PREG_SPLIT_NO_EMPTY);

        return array_map(fn (string $line): string => $this->singleLine((string) preg_replace('/^\s*(?:[-*•]|\d+[.)])\s*/u', '', $line)), $lines);
    }

    private function unfenced(string $reply): string
    {
        return trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($reply)));
    }

    private function singleLine(string $text): string
    {
        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $text));

        return trim($collapsed, " \t\"'`");
    }
}
