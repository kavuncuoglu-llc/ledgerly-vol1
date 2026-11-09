<?php

namespace App\Ai\Tools;

use App\Ai\Http\ChatResponse;

final readonly class ToolRunResult
{
    public function __construct(
        public ChatResponse $response,
        public array $messages,
        public int $rounds,
        public bool $hitCap = false,
        public array $pending = [],
        public int $inputCount = 0,
        public array $modelCallIds = [],
        public ?int $conversationId = null,
        public array $sources = [],
        public bool $citationsOk = true,
    ) {}

    public function answer(): string
    {
        return $this->response->content ?? '';
    }

    public function hasPending(): bool
    {
        return $this->pending !== [];
    }

    public function forConversation(int $conversationId): self
    {
        return $this->copy(['conversationId' => $conversationId]);
    }

    public function withContent(string $content): self
    {
        return $this->copy([
            'response' => $this->response->withContent($content),
        ]);
    }

    public function withCitations(array $sources, bool $ok): self
    {
        return $this->copy([
            'sources' => $sources,
            'citationsOk' => $ok,
        ]);
    }

    public function usedTools(): bool
    {
        foreach (array_slice($this->messages, $this->inputCount) as $message) {
            if (($message['role'] ?? '') === 'tool') {
                return true;
            }
        }

        return false;
    }

    private function copy(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }

    public function newMessages(): array
    {
        $produced = array_slice($this->messages, $this->inputCount);

        if ($this->endedWithAnswer()) {
            $produced[] = [
                'role' => 'assistant',
                'content' => $this->response->content,
            ];
        }

        return $this->withModelCallIds($produced);
    }

    private function endedWithAnswer(): bool
    {
        if ($this->hasPending()) {
            return false;
        }

        if ($this->hitCap) {
            return false;
        }

        return $this->response->toolCalls === [];
    }

    private function withModelCallIds(array $produced): array
    {
        $ids = $this->modelCallIds;

        return array_map(function (array $message) use (&$ids): array {
            if ($message['role'] !== 'assistant') {
                return $message;
            }

            $message['model_call_id'] = array_shift($ids);

            return $message;
        }, $produced);
    }
}
