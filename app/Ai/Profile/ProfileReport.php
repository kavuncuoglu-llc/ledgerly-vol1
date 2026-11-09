<?php

namespace App\Ai\Profile;

final class ProfileReport
{
    public function __construct(
        private readonly array $turns,
        private readonly array $samples,
        private readonly float $startedAt,
    ) {}

    public function rows(): array
    {
        return array_map(fn (MemorySample $sample): array => $this->rowFor($sample), $this->samples);
    }

    public function summary(float $weightsBytes, float $bandwidthGbps): array
    {
        $decode = $this->median(array_map(fn (ProfileTurn $turn): float => $turn->decodeTokensPerSecond(), $this->succeeded()));

        $prefill = $this->median(array_map(fn (ProfileTurn $turn): float => $turn->prefillTokensPerSecond(), $this->warm()));

        $ceiling = $this->ceiling($weightsBytes, $bandwidthGbps);

        return [
            'load_to_first_token' => $this->loadToFirstToken(),
            'prefill_tokens_per_second' => $prefill,
            'decode_tokens_per_second' => $decode,
            'decode_ceiling_tokens_per_second' => $ceiling,
            'decode_share_of_ceiling' => $ceiling > 0 && $decode > 0
                ? $decode / $ceiling
                : 0.0,
            'peak_resident_bytes' => $this->peak(fn (MemorySample $sample): int => $sample->residentBytes),
            'peak_gpu_bytes' => $this->peak(fn (MemorySample $sample): int => $sample->gpuBytes),
            'spilled_bytes' => $this->peak(fn (MemorySample $sample): int => $sample->spilledBytes),
            'turns' => count($this->turns),
            'failed' => count($this->turns) - count($this->succeeded()),
        ];
    }

    private function rowFor(MemorySample $sample): array
    {
        $turn = $this->turnAt($sample->at);

        $phase = $this->phase($turn, $sample->at);

        return [
            'second' => round($sample->at - $this->startedAt, 1),
            'resident_bytes' => $sample->residentBytes,
            'gpu_bytes' => $sample->gpuBytes,
            'phase' => $phase,
            'prefill_tokens_per_second' => $phase === 'prefill'
                ? $turn->prefillTokensPerSecond()
                : 0.0,
            'decode_tokens_per_second' => $phase === 'decode'
                ? $turn->decodeTokensPerSecond()
                : 0.0,
            'context_tokens' => $this->contextFor($turn, $sample->at),
        ];
    }

    private function turnAt(float $at): ?ProfileTurn
    {
        foreach ($this->turns as $turn) {
            if ($at >= $turn->startedAt && $at <= $turn->endedAt) {
                return $turn;
            }
        }

        return null;
    }

    private function phase(?ProfileTurn $turn, float $at): string
    {
        if ($turn === null) {
            return 'idle';
        }

        if ($turn->firstTokenAt > 0 && $at >= $turn->firstTokenAt) {
            return 'decode';
        }

        return $turn->cold
            ? 'load'
            : 'prefill';
    }

    private function contextFor(?ProfileTurn $turn, float $at): int
    {
        if ($turn === null) {
            return 0;
        }

        if ($turn->firstTokenAt <= 0 || $at < $turn->firstTokenAt) {
            return $turn->promptTokens;
        }

        return $turn->promptTokens + $turn->generatedTokensBy($at);
    }

    private function succeeded(): array
    {
        return array_values(array_filter($this->turns, fn (ProfileTurn $turn): bool => $turn->succeeded()));
    }

    private function warm(): array
    {
        return array_values(array_filter($this->succeeded(), fn (ProfileTurn $turn): bool => ! $turn->cold));
    }

    private function loadToFirstToken(): float
    {
        foreach ($this->succeeded() as $turn) {
            if ($turn->cold) {
                return $turn->timeToFirstToken();
            }
        }

        return 0.0;
    }

    private function ceiling(float $weightsBytes, float $bandwidthGbps): float
    {
        if ($weightsBytes <= 0) {
            return 0.0;
        }

        if ($bandwidthGbps <= 0) {
            return 0.0;
        }

        return $bandwidthGbps * 1_000_000_000 / $weightsBytes;
    }

    private function peak(callable $of): int
    {
        if ($this->samples === []) {
            return 0;
        }

        return max(array_map($of, $this->samples));
    }

    private function median(array $values): float
    {
        $values = array_values(array_filter($values, fn (float $value): bool => $value > 0));

        if ($values === []) {
            return 0.0;
        }

        sort($values);

        $middle = intdiv(count($values), 2);

        if (count($values) % 2 === 1) {
            return $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
