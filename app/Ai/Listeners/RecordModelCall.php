<?php

namespace App\Ai\Listeners;

use App\Ai\Events\ModelCalled;
use App\Models\ModelCall;
use Illuminate\Support\Facades\Log;

final class RecordModelCall
{
    public function handle(ModelCalled $event): void
    {
        ModelCall::create([
            'feature' => $event->feature,
            'provider' => $event->provider,
            'model' => $event->model,
            'prompt_tokens' => $event->usage['prompt_tokens'],
            'completion_tokens' => $event->usage['completion_tokens'],
            'finish_reason' => $event->finishReason,
            'latency_ms' => $event->latencyMs,
            'cost_micros' => $this->costMicros($event),
            'conversation_id' => $event->conversationId,
            'prompt_version' => $event->promptVersion,
            'repaired' => $event->repaired,
        ]);

        $this->warnWhenTruncated($event);
    }

    private function costMicros(ModelCalled $event): int
    {
        $rates = $this->ratesFor($event->provider, $event->model);

        return (int) round(
            $event->usage['prompt_tokens'] * $rates['in']
            + $event->usage['completion_tokens'] * $rates['out']
        );
    }

    private function ratesFor(string $provider, string $model): array
    {
        $providerRates = config("ai.rates.{$provider}", []);

        return $providerRates[$model] ?? [
            'in' => 0,
            'out' => 0,
        ];
    }

    private function warnWhenTruncated(ModelCalled $event): void
    {
        if ($event->finishReason !== 'length') {
            return;
        }

        Log::warning('Completion truncated', [
            'feature' => $event->feature,
            'usage' => $event->usage,
        ]);
    }
}
