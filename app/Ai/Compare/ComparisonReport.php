<?php

namespace App\Ai\Compare;

final class ComparisonReport
{
    public const PER_THOUSAND = 1000;

    public function __construct(private readonly array $outcomes) {}

    public function features(): array
    {
        return array_values(array_unique(array_map(fn (CaseOutcome $outcome): string => $outcome->feature, $this->outcomes)));
    }

    public function providers(string $feature): array
    {
        return array_values(array_unique(array_map(
            fn (CaseOutcome $outcome): string => $outcome->provider,
            $this->forFeature($feature),
        )));
    }

    public function categories(string $feature): array
    {
        $categories = array_values(array_unique(array_map(
            fn (CaseOutcome $outcome): string => $outcome->category,
            $this->forFeature($feature),
        )));

        sort($categories);

        return $categories;
    }

    public function summary(string $feature, string $provider): array
    {
        $outcomes = $this->forProvider($feature, $provider);

        $answered = array_values(array_filter($outcomes, fn (CaseOutcome $outcome): bool => ! $outcome->failed()));

        $cost = $this->costPerThousand($answered);

        return [
            'feature' => $feature,
            'provider' => $provider,
            'cases' => count($outcomes),
            'errors' => count($outcomes) - count($answered),
            'quality' => $this->mean(array_map(fn (CaseOutcome $outcome): float => $outcome->quality, $outcomes)),
            'prompt_tokens' => $this->mean(array_map(fn (CaseOutcome $outcome): float => $outcome->promptTokens, $answered)),
            'completion_tokens' => $this->mean(array_map(fn (CaseOutcome $outcome): float => $outcome->completionTokens, $answered)),
            'p50_ms' => $this->median(array_map(fn (CaseOutcome $outcome): float => $outcome->latencyMs, $answered)),
            'ttft_ms' => $this->median(array_filter(array_map(fn (CaseOutcome $outcome): ?float => $outcome->ttftMs, $answered), fn (?float $value): bool => $value !== null)),
            'cost_per_1k' => $cost['usd'],
            'cost_basis' => $cost['basis'],
            'quality_per_dollar' => $this->qualityPerDollar($this->mean(array_map(fn (CaseOutcome $outcome): float => $outcome->quality, $outcomes)), $cost),
        ];
    }

    public function qualityByCategory(string $feature, string $provider): array
    {
        $byCategory = [];

        foreach ($this->forProvider($feature, $provider) as $outcome) {
            $byCategory[$outcome->category][] = $outcome->quality;
        }

        ksort($byCategory);

        return array_map($this->mean(...), $byCategory);
    }

    public function toArray(): array
    {
        $features = [];

        foreach ($this->features() as $feature) {
            $features[$feature] = [
                'providers' => array_map(fn (string $provider): array => $this->serializable($this->summary($feature, $provider)), $this->providers($feature)),
                'by_category' => array_combine(
                    $this->providers($feature),
                    array_map(fn (string $provider): array => $this->qualityByCategory($feature, $provider), $this->providers($feature)),
                ),
            ];
        }

        return [
            'features' => $features,
            'outcomes' => array_map(fn (CaseOutcome $outcome): array => $outcome->toArray(), $this->outcomes),
        ];
    }

    public function outcomes(): array
    {
        return $this->outcomes;
    }

    private function serializable(array $summary): array
    {
        if ($summary['quality_per_dollar'] === INF) {
            $summary['quality_per_dollar'] = 'infinite';
        }

        return $summary;
    }

    private function forFeature(string $feature): array
    {
        return array_values(array_filter($this->outcomes, fn (CaseOutcome $outcome): bool => $outcome->feature === $feature));
    }

    private function forProvider(string $feature, string $provider): array
    {
        return array_values(array_filter($this->forFeature($feature), fn (CaseOutcome $outcome): bool => $outcome->provider === $provider));
    }

    private function costPerThousand(array $answered): array
    {
        if ($answered === []) {
            return [
                'usd' => null,
                'basis' => Cost::BASIS_UNKNOWN,
            ];
        }

        $basis = $answered[0]->cost?->basis ?? Cost::BASIS_UNKNOWN;

        if ($basis === Cost::BASIS_UNKNOWN) {
            return [
                'usd' => null,
                'basis' => $basis,
            ];
        }

        return [
            'usd' => $this->mean(array_map(fn (CaseOutcome $outcome): float => $outcome->cost->usd, $answered)) * self::PER_THOUSAND,
            'basis' => $basis,
        ];
    }

    private function qualityPerDollar(float $quality, array $cost): ?float
    {
        if ($cost['usd'] === null) {
            return null;
        }

        if ($cost['usd'] <= 0.0) {
            return INF;
        }

        return $quality / $cost['usd'];
    }

    private function mean(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        $values = array_values($values);

        sort($values);

        $middle = intdiv(count($values), 2);

        if (count($values) % 2 === 1) {
            return $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
