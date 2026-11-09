<?php

namespace App\Ai\Doctor\Triton\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Triton\TritonCheck;
use App\Ai\Doctor\Triton\TritonDoctorContext;
use App\Ai\Triton\TritonMetrics;

final class MetricsCheck implements TritonCheck
{
    public function run(TritonDoctorContext $context): array
    {
        $reply = $context->metricsReply();

        if (! $reply->ok()) {
            return [CheckResult::warn("/metrics answered {$reply->errorMessage()}: there are no per-model counts, queue times or GPU figures to scrape.")];
        }

        $metrics = $context->metrics();

        if ($metrics->isEmpty()) {
            return [CheckResult::warn('/metrics answered 200 but no nv_ series were found in the body.')];
        }

        $results = [];

        foreach ($metrics->models() as $model) {
            $results[] = $this->model($metrics, $model);
        }

        return [...$results, ...$this->gpu($metrics)];
    }

    private function model(TritonMetrics $metrics, string $model): CheckResult
    {
        $failures = $metrics->failures($model);

        $line = sprintf(
            'model %s: %d ok · %d failed · average batch %.1f · average queue %.1f ms',
            $model,
            $metrics->successes($model),
            $failures,
            $metrics->averageBatchSize($model),
            $metrics->averageQueueMilliseconds($model),
        );

        if ($failures > 0) {
            return CheckResult::warn($line.': read the server log for the failed requests.');
        }

        return CheckResult::pass($line);
    }

    private function gpu(TritonMetrics $metrics): array
    {
        if (! $metrics->hasGpu()) {
            return [];
        }

        return [CheckResult::pass(sprintf('GPU %d%% utilized · memory %d%% used', round($metrics->gpuUtilization() * 100), round($metrics->gpuMemoryUsedFraction() * 100)))];
    }
}
