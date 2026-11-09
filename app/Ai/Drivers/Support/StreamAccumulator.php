<?php

namespace App\Ai\Drivers\Support;

use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\FinishReason;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Contracts\Stream\ToolCallArguments;
use App\Ai\Contracts\Stream\ToolCallStarted;
use App\Ai\Contracts\Usage;

final class StreamAccumulator
{
    private string $id = '';

    private string $model = '';

    private string $content = '';

    private ?FinishReason $finishReason = null;

    private ?Usage $usage = null;

    private bool $done = false;

    private array $toolCalls = [];

    private array $announced = [];

    private array $pending = [];

    public function identify(string $id, string $model): void
    {
        $this->id = $this->id !== '' ? $this->id : $id;
        $this->model = $this->model !== '' ? $this->model : $model;
    }

    public function text(string $delta): void
    {
        if ($delta === '') {
            return;
        }

        $this->content .= $delta;

        $this->pending[] = new Delta($delta);
    }

    public function toolCall(int $index, ?string $id, ?string $name, string $arguments): void
    {
        $call = $this->toolCalls[$index] ?? [
            'id' => null,
            'name' => null,
            'arguments' => '',
        ];

        $call['id'] ??= $id;
        $call['name'] ??= $name;
        $call['arguments'] .= $arguments;

        $this->toolCalls[$index] = $call;

        $this->announceWhenNamed($index, $call['name']);

        if ($arguments !== '') {
            $this->pending[] = new ToolCallArguments($index, $arguments);
        }
    }

    public function finish(FinishReason $reason): void
    {
        $this->finishReason = $reason;
    }

    public function reportUsage(Usage $usage): void
    {
        $this->usage = $usage;
    }

    public function markDone(): void
    {
        $this->done = true;
    }

    public function drain(): array
    {
        $events = $this->pending;

        $this->pending = [];

        return $events;
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function finishReason(): ?FinishReason
    {
        return $this->finishReason;
    }

    public function hasUsage(): bool
    {
        return $this->usage !== null;
    }

    public function model(string $fallback): string
    {
        return $this->model !== ''
            ? $this->model
            : $fallback;
    }

    public function toolCalls(): array
    {
        ksort($this->toolCalls);

        return array_values(array_map(
            fn (array $call, int $index): array => [
                'id' => (string) ($call['id'] ?? 'call_'.$index),
                'type' => 'function',
                'function' => [
                    'name' => (string) $call['name'],
                    'arguments' => $call['arguments'],
                ],
            ],
            $this->toolCalls,
            array_keys($this->toolCalls),
        ));
    }

    public function toResponse(string $fallbackModel, ?Usage $estimate = null, ?FinishReason $override = null): ChatResponse
    {
        $toolCalls = $this->toolCalls();

        return new ChatResponse(
            id: $this->id,
            model: $this->model($fallbackModel),
            content: $this->contentFor($toolCalls),
            toolCalls: $toolCalls,
            finishReason: $override ?? $this->finishReason ?? FinishReason::Stop,
            usage: $this->usage ?? $estimate ?? Usage::empty(),
            raw: [],
        );
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

        $this->pending[] = new ToolCallStarted($index, $name);
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
