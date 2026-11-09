<?php

namespace App\Ai\Doctor\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\DoctorCheck;
use App\Ai\Doctor\DoctorContext;

final class RoundTripCheck implements DoctorCheck
{
    public function run(DoctorContext $context): array
    {
        $outcome = $context->roundTrip();

        $compatible = $outcome->compatible;

        $native = $outcome->native;

        if (! $compatible['ok']) {
            return [CheckResult::fail("/v1/chat/completions round trip failed: {$compatible['error']}")];
        }

        if (! $native['ok']) {
            return [CheckResult::fail("/api/chat round trip failed: {$native['error']}")];
        }

        if (! isset($native['body']['prompt_eval_count'], $native['body']['eval_count'])) {
            return [CheckResult::fail('/api/chat answered without prompt_eval_count and eval_count, so model_calls would not get exact counts')];
        }

        return [CheckResult::pass(sprintf(
            '/v1/chat/completions round trip %s · /api/chat round trip %s with exact counts (%d prompt, %d generated)',
            $this->seconds($compatible['milliseconds']),
            $this->seconds($native['milliseconds']),
            $native['body']['prompt_eval_count'],
            $native['body']['eval_count'],
        ))];
    }

    private function seconds(int $milliseconds): string
    {
        return number_format($milliseconds / 1000, 1).' s';
    }
}
