<?php

namespace App\Ai\Load;

use App\Models\ModelCall;
use Carbon\CarbonInterface;

final class TrafficShapeExporter
{
    private const ANSWER_FINISH_REASONS = ['stop', 'length'];

    private const PREFIX_SLOTS = ['system', 'memories'];

    public function export(string $feature, CarbonInterface $since, int $limit): array
    {
        return ModelCall::query()
            ->where('feature', $feature)
            ->where('created_at', '>=', $since)
            ->where('prompt_tokens', '>', 0)
            ->where('completion_tokens', '>', 0)
            ->whereIn('finish_reason', self::ANSWER_FINISH_REASONS)
            ->where(fn ($query) => $query->whereNull('semantic_cache_hit')->orWhere('semantic_cache_hit', false))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (ModelCall $call): TrafficShape => $this->shapeOf($call))
            ->values()
            ->all();
    }

    private function shapeOf(ModelCall $call): TrafficShape
    {
        return new TrafficShape(
            feature: $call->feature,
            inputTokens: $call->prompt_tokens,
            outputTokens: $call->completion_tokens,
            prefixTokens: min($this->prefixOf($call), $call->prompt_tokens),
        );
    }

    private function prefixOf(ModelCall $call): int
    {
        $breakdown = (array) $call->context_breakdown;

        $tokens = 0;

        foreach (self::PREFIX_SLOTS as $slot) {
            $tokens += (int) ($breakdown[$slot] ?? 0);
        }

        return $tokens;
    }
}
