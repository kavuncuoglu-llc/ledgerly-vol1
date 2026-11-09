<?php

namespace App\Ai\Conversations;

use App\Models\Message;
use Illuminate\Support\Collection;

final class Exchange
{
    public function __construct(private readonly Collection $messages) {}

    public function messages(): Collection
    {
        return $this->messages;
    }

    public function tokens(): int
    {
        return (int) $this->messages->sum('tokens');
    }

    public function firstSeq(): int
    {
        return (int) $this->messages->first()->seq;
    }

    public function lastSeq(): int
    {
        return (int) $this->messages->last()->seq;
    }

    public function toApiMessages(): array
    {
        return $this->messages
            ->map(fn (Message $message): array => $message->toApiMessage())
            ->all();
    }

    public function toolNameFor(string $toolCallId): string
    {
        foreach ($this->messages as $message) {
            foreach ($message->tool_calls ?? [] as $call) {
                if (($call['id'] ?? null) === $toolCallId) {
                    return (string) ($call['function']['name'] ?? '');
                }
            }
        }

        return '';
    }
}
