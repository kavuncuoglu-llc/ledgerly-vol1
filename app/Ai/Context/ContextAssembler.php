<?php

namespace App\Ai\Context;

use App\Ai\Retrieval\LabelMap;
use App\Ai\Tokens\TokenCounter;

final class ContextAssembler
{
    private string $systemText = '';

    private string $memoriesText = '';

    private string $summaryText = '';

    private array $historyMessages = [];

    private array $contextChunks = [];

    private string $questionText = '';

    public function __construct(private readonly TokenCounter $counter) {}

    public function system(string $text): self
    {
        $this->systemText = $text;

        return $this;
    }

    public function memories(string $block): self
    {
        $this->memoriesText = $block;

        return $this;
    }

    public function summary(?string $note): self
    {
        $this->summaryText = (string) $note;

        return $this;
    }

    public function history(array $messages): self
    {
        $this->historyMessages = $messages;

        return $this;
    }

    public function context(array $chunks): self
    {
        $this->contextChunks = $this->chunksWithinBudget($chunks);

        return $this;
    }

    public function question(string $text): self
    {
        $this->questionText = $text;

        return $this;
    }

    public function build(): AssembledPrompt
    {
        $stable = [$this->systemMessages(), $this->summaryMessages()];

        $messages = array_values(array_filter([
            ...$stable[0],
            ...$stable[1],
            ...$this->historyMessages,
            ...$this->contextMessages(),
            ...$this->questionMessages(),
        ]));

        $breakdown = $this->breakdown();

        return new AssembledPrompt(
            messages: $messages,
            breakdown: $breakdown,
            overBudget: $this->overBudget($breakdown),
            labelMap: LabelMap::for($this->contextChunks),
            breakpoints: $this->breakpointsAfter($stable),
        );
    }

    private function systemMessages(): array
    {
        if ($this->systemText === '') {
            return [];
        }

        return [
            [
                'role' => 'system',
                'content' => $this->systemContent(),
            ],
        ];
    }

    private function systemContent(): string
    {
        if ($this->memoriesText === '') {
            return $this->systemText;
        }

        return $this->systemText."\n\n".$this->memoriesText;
    }

    private function summaryMessages(): array
    {
        if ($this->summaryText === '') {
            return [];
        }

        return [
            [
                'role' => 'system',
                'content' => $this->summaryText,
            ],
        ];
    }

    private function contextMessages(): array
    {
        if ($this->contextChunks === []) {
            return [];
        }

        return [
            [
                'role' => 'user',
                'content' => ContextBlock::render($this->contextChunks),
            ],
        ];
    }

    private function questionMessages(): array
    {
        if ($this->questionText === '') {
            return [];
        }

        return [
            [
                'role' => 'user',
                'content' => $this->questionText,
            ],
        ];
    }

    private function breakpointsAfter(array $stableSlots): array
    {
        $breakpoints = [];

        $count = 0;

        foreach ($stableSlots as $slotMessages) {
            $count += count(array_filter($slotMessages));

            if ($slotMessages !== []) {
                $breakpoints[] = $count - 1;
            }
        }

        return $breakpoints;
    }

    private function breakdown(): array
    {
        return array_filter([
            'system' => $this->counter->count($this->systemText),
            'memories' => $this->counter->count($this->memoriesText),
            'summary' => $this->counter->count($this->summaryText),
            'history' => $this->historyTokens(),
            'context' => $this->contextTokens(),
            'question' => $this->counter->count($this->questionText),
        ], fn (int $tokens, string $slot): bool => $tokens > 0 || $slot === 'system', ARRAY_FILTER_USE_BOTH);
    }

    private function historyTokens(): int
    {
        $tokens = 0;

        foreach ($this->historyMessages as $message) {
            $tokens += $this->counter->count((string) ($message['content'] ?? ''));

            $tokens += $this->toolCallTokens($message);
        }

        return $tokens;
    }

    private function toolCallTokens(array $message): int
    {
        if (empty($message['tool_calls'])) {
            return 0;
        }

        return $this->counter->count(json_encode($message['tool_calls']));
    }

    private function contextTokens(): int
    {
        if ($this->contextChunks === []) {
            return 0;
        }

        return $this->counter->count(ContextBlock::render($this->contextChunks));
    }

    private function chunksWithinBudget(array $chunks): array
    {
        $cap = (int) config('ai.slot_budgets.context');

        $kept = [];

        foreach ($chunks as $chunk) {
            $candidate = [...$kept, $chunk];

            if ($kept !== [] && $this->counter->count(ContextBlock::render($candidate)) > $cap) {
                break;
            }

            $kept = $candidate;
        }

        return $kept;
    }

    private function overBudget(array $breakdown): array
    {
        $caps = [
            'system' => (int) config('ai.budget.system'),
            ...config('ai.slot_budgets'),
        ];

        $over = [];

        foreach ($caps as $slot => $cap) {
            if (($breakdown[$slot] ?? 0) > $cap) {
                $over[] = $slot;
            }
        }

        return $over;
    }
}
