<?php

namespace App\Ai\Doctor\Triton\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Triton\TritonCheck;
use App\Ai\Doctor\Triton\TritonDoctorContext;

final class ModelConfigCheck implements TritonCheck
{
    public function run(TritonDoctorContext $context): array
    {
        if (! $context->kserve->hasKserveUrl()) {
            return [];
        }

        $results = [];

        foreach ($context->declaredModels() as $model) {
            $results = [...$results, ...$this->check($context, $model)];
        }

        return $results;
    }

    private function check(TritonDoctorContext $context, string $model): array
    {
        $reply = $context->kserve->modelConfig($model);

        if (! $reply->ok()) {
            return [CheckResult::warn("model {$model}: /v2/models/{$model}/config answered {$reply->errorMessage()}")];
        }

        $config = $reply->body;

        $batch = (int) ($config['max_batch_size'] ?? 0);

        $instances = $this->instances($config);

        $facts = implode(' · ', array_filter([
            'backend '.($config['backend'] ?? $config['platform'] ?? 'unknown'),
            "max_batch_size {$batch}",
            "{$instances} instance".($instances === 1 ? '' : 's'),
            $this->batching($config),
        ]));

        $results = [CheckResult::pass("model {$model}: {$facts}")];

        if ($batch > 0 && ! isset($config['dynamic_batching']) && ! isset($config['sequence_batching'])) {
            $results[] = CheckResult::warn("model {$model} takes batches but declares no dynamic_batching: concurrent requests run one at a time instead of being merged into one forward pass.");
        }

        if ($model !== $context->model() && ! isset($config['model_warmup'])) {
            $results[] = CheckResult::warn("model {$model} declares no model_warmup: its first request pays the compile and cache cost. ai:warm covers the chat model; every other model needs a model_warmup block in config.pbtxt.");
        }

        return $results;
    }

    private function instances(array $config): int
    {
        $total = 0;

        foreach ((array) ($config['instance_group'] ?? []) as $group) {
            $total += (int) ($group['count'] ?? 1);
        }

        return $total;
    }

    private function batching(array $config): string
    {
        if (! isset($config['dynamic_batching'])) {
            return '';
        }

        $delay = $config['dynamic_batching']['max_queue_delay_microseconds'] ?? null;

        if ($delay === null) {
            return 'dynamic batching';
        }

        return 'dynamic batching ('.((int) $delay / 1000).' ms window)';
    }
}
