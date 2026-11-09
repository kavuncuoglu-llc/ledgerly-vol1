<?php

namespace App\Ai\Agents;

use Illuminate\Support\Str;

final readonly class AgentState
{
    public function __construct(
        public string $id,
        public string $goal,
        public array $facts = [],
        public array $steps = [],
        public array $findings = [],
        public string $conclusion = '',
    ) {}

    public static function fresh(string $goal, array $facts = []): self
    {
        return new self(
            id: (string) Str::uuid(),
            goal: $goal,
            facts: $facts,
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            goal: (string) $data['goal'],
            facts: $data['facts'] ?? [],
            steps: $data['steps'] ?? [],
            findings: $data['findings'] ?? [],
            conclusion: (string) ($data['conclusion'] ?? ''),
        );
    }

    public function hasStep(string $signature): bool
    {
        return isset($this->steps[$signature]);
    }

    public function withStep(string $signature, array $step): self
    {
        return $this->with([
            'steps' => [
                ...$this->steps,
                $signature => $step,
            ],
        ]);
    }

    public function withFinding(string $key, mixed $value): self
    {
        return $this->with([
            'findings' => [
                ...$this->findings,
                $key => $value,
            ],
        ]);
    }

    public function withFindingAppended(string $key, mixed $value): self
    {
        $existing = $this->findings[$key] ?? [];

        if (in_array($value, $existing, true)) {
            return $this;
        }

        return $this->withFinding($key, [...$existing, $value]);
    }

    public function withConclusion(string $conclusion): self
    {
        return $this->with(['conclusion' => $conclusion]);
    }

    public function findings(): array
    {
        if ($this->conclusion === '') {
            return $this->findings;
        }

        return [
            ...$this->findings,
            'conclusion' => $this->conclusion,
        ];
    }

    public function hash(): string
    {
        return sha1((string) json_encode([
            $this->facts,
            $this->steps,
            $this->findings,
            $this->conclusion,
        ]));
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'goal' => $this->goal,
            'facts' => $this->facts,
            'steps' => $this->steps,
            'findings' => $this->findings,
            'conclusion' => $this->conclusion,
        ];
    }

    private function with(array $changes): self
    {
        return self::fromArray([
            ...$this->toArray(),
            ...$changes,
        ]);
    }
}
