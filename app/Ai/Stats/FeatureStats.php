<?php

namespace App\Ai\Stats;

use App\Models\ModelCall;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class FeatureStats
{
    public function between(string $feature, CarbonInterface $since, ?CarbonInterface $until = null): FeatureSnapshot
    {
        $calls = $this->callsBetween($feature, $since, $until);

        $modelCalls = $calls->filter(fn (ModelCall $call): bool => $call->semantic_cache_hit !== true);

        return new FeatureSnapshot(
            calls: $calls->count(),
            promptCacheHitRate: $this->promptCacheHitRate($modelCalls),
            promptCacheEstimated: $modelCalls->contains(fn (ModelCall $call): bool => $call->cached_tokens_estimated),
            semanticHitRate: $this->semanticHitRate($calls),
            semanticHits: $calls->where('semantic_cache_hit', true)->count(),
            semanticLookups: $calls->whereNotNull('semantic_cache_hit')->count(),
            tokensPerAnswer: $this->tokensPerAnswer($modelCalls),
            slots: $this->averageSlots($modelCalls),
            averageTtftMs: $this->average($modelCalls->pluck('ttft_ms')),
            averageLatencyMs: $this->average($modelCalls->pluck('latency_ms')),
            citationFlagRate: $this->citationFlagRate($modelCalls),
            citationFlagged: $modelCalls->where('citation_errors', '>', 0)->count(),
            citationChecked: $modelCalls->whereNotNull('citation_errors')->count(),
        );
    }

    private function callsBetween(string $feature, CarbonInterface $since, ?CarbonInterface $until): Collection
    {
        return ModelCall::query()
            ->where('feature', $feature)
            ->where('created_at', '>=', $since)
            ->when($until, fn ($query) => $query->where('created_at', '<', $until))
            ->get();
    }

    private function promptCacheHitRate(Collection $calls): ?float
    {
        $tracked = $calls->whereNotNull('cached_tokens');

        $promptTokens = $tracked->sum('prompt_tokens');

        if ($promptTokens === 0) {
            return null;
        }

        return $tracked->sum('cached_tokens') / $promptTokens;
    }

    private function semanticHitRate(Collection $calls): ?float
    {
        $lookups = $calls->whereNotNull('semantic_cache_hit');

        if ($lookups->isEmpty()) {
            return null;
        }

        return $lookups->where('semantic_cache_hit', true)->count() / $lookups->count();
    }

    private function citationFlagRate(Collection $calls): ?float
    {
        $checked = $calls->whereNotNull('citation_errors');

        if ($checked->isEmpty()) {
            return null;
        }

        return $checked->where('citation_errors', '>', 0)->count() / $checked->count();
    }

    private function tokensPerAnswer(Collection $calls): ?int
    {
        $answers = $calls->where('finish_reason', '!=', 'tool_calls')->count();

        if ($answers === 0) {
            return null;
        }

        return (int) round($calls->sum('prompt_tokens') / $answers);
    }

    private function averageSlots(Collection $calls): array
    {
        $withBreakdown = $calls->filter(fn (ModelCall $call): bool => ! empty($call->context_breakdown));

        $totals = [];

        foreach ($withBreakdown as $call) {
            foreach ($call->context_breakdown as $slot => $tokens) {
                $totals[$slot] = ($totals[$slot] ?? 0) + $tokens;
            }
        }

        return array_map(
            fn (int $total): int => (int) round($total / $withBreakdown->count()),
            $totals,
        );
    }

    private function average(Collection $values): ?int
    {
        $present = $values->filter(fn (mixed $value): bool => $value !== null);

        if ($present->isEmpty()) {
            return null;
        }

        return (int) round($present->avg());
    }
}
