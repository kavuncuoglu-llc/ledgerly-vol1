<?php

namespace App\Ai\Agents;

use App\Ai\Http\ChatResponse;
use Illuminate\Support\Str;

final class Trace
{
    private float $startedAt;

    public function __construct(
        public readonly string $agent,
        public readonly string $stateId,
        private array $entries = [],
    ) {
        $this->startedAt = $this->clock() - $this->lastOffset();
    }

    public static function fromArray(string $agent, string $stateId, array $entries): self
    {
        return new self($agent, $stateId, $entries);
    }

    public function goal(string $goal): void
    {
        $this->add(0, 'goal', 'goal: '.$goal);
    }

    public function decision(int $round, ChatResponse $response): void
    {
        if ($response->toolCalls === []) {
            $this->add($round, 'decision', 'decision → done: '.Str::limit(trim((string) $response->content), 120));

            return;
        }

        foreach ($response->toolCalls as $call) {
            $this->add($round, 'decision', 'decision → '.CallSignature::describe($call));
        }
    }

    public function observation(int $round, string $summary): void
    {
        $this->add($round, 'result', 'result: '.$summary);
    }

    public function skipped(int $round, int $count): void
    {
        $this->add($round, 'budget', "skipped {$count} further tool calls in this round");
    }

    public function budget(int $round, string $reason): void
    {
        $this->add($round, 'budget', "budget: {$reason} reached → run ends (exhausted: {$reason})");
    }

    public function failure(int $round, string $message): void
    {
        $this->add($round, 'failure', 'failed: '.Str::limit($message, 200));
    }

    public function findings(array $findings): void
    {
        $this->add(0, 'findings', 'findings: '.json_encode($findings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function entries(): array
    {
        return $this->entries;
    }

    public function lines(): array
    {
        return array_map(function (array $entry): string {
            $round = $entry['round'] > 0
                ? "r{$entry['round']} "
                : '';

            return sprintf('[%04.1fs] %s%s', $entry['t'], $round, $entry['text']);
        }, $this->entries);
    }

    public function toArray(): array
    {
        return $this->entries;
    }

    private function add(int $round, string $kind, string $text): void
    {
        $this->entries[] = [
            't' => round($this->clock() - $this->startedAt, 1),
            'round' => $round,
            'kind' => $kind,
            'text' => $text,
        ];
    }

    private function lastOffset(): float
    {
        if ($this->entries === []) {
            return 0.0;
        }

        return (float) $this->entries[array_key_last($this->entries)]['t'];
    }

    private function clock(): float
    {
        return (float) now()->format('U.u');
    }
}
