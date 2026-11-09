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

    private array $toolCalls = [];

    private array $announced = [];

    private array $startedToolCalls = [];

    public function absorb(array $chunk): string
    {
        $this->id = $this->id !== '' ? $this->id : (string) ($chunk['id'] ?? '');
        $this->model = $this->model !== '' ? $this->model : (string) ($chunk['model'] ?? '');
        $this->finishReason = $chunk['choices'][0]['finish_reason'] ?? $this->finishReason;
        $this->usage = $chunk['usage'] ?? $this->usage;

        $delta = (string) ($chunk['choices'][0]['delta']['content'] ?? '');
        $this->content .= $delta;

        $this->absorbToolCalls($chunk['choices'][0]['delta']['tool_calls'] ?? []);

        return $delta;
    }

    public function drainStartedToolCalls(): array
    {
        $started = $this->startedToolCalls;

        $this->startedToolCalls = [];

        return $started;
    }

    public function toolCalls(): array
    {
        ksort($this->toolCalls);

        return array_values(array_map(
            fn (array $call): array => [
                'id' => (string) $call['id'],
                'type' => 'function',
                'function' => [
                    'name' => (string) $call['name'],
                    'arguments' => $call['arguments'],
                ],
            ],
            $this->toolCalls,
        ));
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
        $toolCalls = $this->toolCalls();

        return new ChatResponse(
            id: $this->id,
            model: $this->model($fallbackModel),
            content: $this->contentFor($toolCalls),
            toolCalls: $toolCalls,
            finishReason: $this->finishReason ?? 'stop',
            usage: $this->usage(),
            raw: [],
        );
    }

    private function absorbToolCalls(array $fragments): void
    {
        foreach ($fragments as $fragment) {
            $index = (int) ($fragment['index'] ?? 0);

            $call = $this->toolCalls[$index] ?? [
                'id' => null,
                'name' => null,
                'arguments' => '',
            ];

            $call['id'] ??= $fragment['id'] ?? null;
            $call['name'] ??= $fragment['function']['name'] ?? null;
            $call['arguments'] .= $fragment['function']['arguments'] ?? '';

            $this->toolCalls[$index] = $call;

            $this->announceWhenNamed($index, $call['name']);
        }
    }

    private function announceWhenNamed(int $index, ?string $name): void
    {
        if ($name === null) {
            return;
        }

        if ($this->announced[$index] ?? false) {
            return;
        }

        $this->announced[$index] = true;

        $this->startedToolCalls[] = new ToolCallStarted($index, $name);
    }

    private function contentFor(array $toolCalls): ?string
    {
        if ($toolCalls === []) {
            return $this->content;
        }

        if ($this->content === '') {
            return null;
        }

        return $this->content;
    }
}
