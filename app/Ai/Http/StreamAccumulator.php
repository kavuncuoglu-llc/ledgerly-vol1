<?php

namespace App\Ai\Http;

final class StreamAccumulator
{
    private string $id = '';

    private string $model = '';

    private string $content = '';

    private ?string $finishReason = null;

    private ?array $usage = null;

    private bool $done = false;

    public function absorb(array $chunk): string
    {
        $this->id = $this->id !== '' ? $this->id : (string) ($chunk['id'] ?? '');
        $this->model = $this->model !== '' ? $this->model : (string) ($chunk['model'] ?? '');
        $this->finishReason = $chunk['choices'][0]['finish_reason'] ?? $this->finishReason;
        $this->usage = $chunk['usage'] ?? $this->usage;

        $delta = (string) ($chunk['choices'][0]['delta']['content'] ?? '');
        $this->content .= $delta;

        return $delta;
    }

    public function markDone(): void
    {
        $this->done = true;
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function finishReason(): ?string
    {
        return $this->finishReason;
    }

    public function model(string $fallback): string
    {
        return $this->model !== ''
            ? $this->model
            : $fallback;
    }

    public function usage(): Usage
    {
        return Usage::fromArray($this->usage ?? []);
    }

    public function toResponse(string $fallbackModel): ChatResponse
    {
        return new ChatResponse(
            id: $this->id,
            model: $this->model($fallbackModel),
            content: $this->content,
            toolCalls: [],
            finishReason: $this->finishReason ?? 'stop',
            usage: $this->usage(),
            raw: [],
        );
    }
}
