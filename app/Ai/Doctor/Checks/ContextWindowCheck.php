<?php

namespace App\Ai\Doctor\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\DoctorCheck;
use App\Ai\Doctor\DoctorContext;

final class ContextWindowCheck implements DoctorCheck
{
    private const WASTE_FACTOR = 2;

    public function run(DoctorContext $context): array
    {
        $results = [];

        foreach ($context->chatProviders() as $config) {
            $model = (string) ($config['model'] ?? '');

            if ($context->installedModel($model) === []) {
                continue;
            }

            $results[] = $this->checkModel($context, $model);
        }

        return [...$results, ...$this->nativeRequestWindow($context)];
    }

    private function checkModel(DoctorContext $context, string $model): CheckResult
    {
        $effective = $this->effective($context, $model);

        if ($effective['tokens'] === 0) {
            return CheckResult::warn("{$model} num_ctx unknown: not set in its Modelfile and the model is not loaded, so the server default applies (4096 on small GPUs, 32768 on this class of machine). Pin num_ctx {$context->window} in a Modelfile.");
        }

        $tokens = $effective['tokens'];

        $source = $effective['source'];

        if ($tokens < $context->window) {
            return CheckResult::fail("{$model} num_ctx {$tokens} ({$source}) < budget window {$context->window}: prompts will be silently truncated. Set num_ctx in the Modelfile.");
        }

        if ($tokens >= $context->window * self::WASTE_FACTOR) {
            return CheckResult::warn("{$model} num_ctx {$tokens} ({$source}) is {$this->ratio($tokens, $context->window)} times the budget window {$context->window}: the KV cache is allocated for all of it when the model loads. Pin num_ctx {$context->window} in the Modelfile.");
        }

        return CheckResult::pass("{$model} num_ctx {$tokens} ({$source}) >= budget window {$context->window}");
    }

    private function effective(DoctorContext $context, string $model): array
    {
        if ($model === $context->primaryModel()) {
            $loaded = $context->loaded($model, $context->roundTrip()->runningAfterCompatible);

            if (($loaded['context_length'] ?? 0) > 0) {
                return [
                    'tokens' => (int) $loaded['context_length'],
                    'source' => 'loaded, as served to /v1',
                ];
            }
        }

        $fromModelfile = $this->modelfileNumCtx($context, $model);

        return [
            'tokens' => $fromModelfile,
            'source' => 'Modelfile',
        ];
    }

    private function modelfileNumCtx(DoctorContext $context, string $model): int
    {
        $parameters = (string) ($context->show($model)['parameters'] ?? '');

        if (preg_match('/^\s*num_ctx\s+(\d+)/m', $parameters, $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }

    private function nativeRequestWindow(DoctorContext $context): array
    {
        $requested = (int) config('ai.ollama.num_ctx');

        if ($requested === 0) {
            return [];
        }

        if ($requested < $context->window) {
            return [CheckResult::fail("AI_NUM_CTX {$requested} < budget window {$context->window}: native requests would truncate prompts.")];
        }

        $loaded = $context->loaded($context->primaryModel(), $context->roundTrip()->runningAfterCompatible);

        $served = (int) ($loaded['context_length'] ?? 0);

        if ($served === 0) {
            return [];
        }

        if ($served === $requested) {
            return [];
        }

        return [CheckResult::warn("native requests ask for num_ctx {$requested} but /v1 traffic is served at {$served}: Ollama reloads the model whenever the context changes between requests. Pin {$requested} in the Modelfile so both agree.")];
    }

    private function ratio(int $tokens, int $window): string
    {
        return rtrim(rtrim(number_format($tokens / $window, 1), '0'), '.');
    }
}
