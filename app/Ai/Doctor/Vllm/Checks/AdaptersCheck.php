<?php

namespace App\Ai\Doctor\Vllm\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Vllm\VllmCheck;
use App\Ai\Doctor\Vllm\VllmDoctorContext;

final class AdaptersCheck implements VllmCheck
{
    public function run(VllmDoctorContext $context): array
    {
        $routes = $this->routedModels($context);

        if ($routes === []) {
            return [];
        }

        if (! $context->modelsReply()->ok()) {
            return [];
        }

        $missing = [];

        $lines = [];

        foreach ($routes as $feature => $model) {
            $card = $context->servedModel($model);

            if ($card === []) {
                $missing[] = "{$feature} -> {$model}";

                continue;
            }

            $lines[] = $this->describe($feature, $model, $card);
        }

        if ($missing !== []) {
            return [CheckResult::fail('feature routed to a model this server does not serve: '.implode(', ', $missing).'. Load the adapter (--lora-modules, or POST /v1/load_lora_adapter with VLLM_ALLOW_RUNTIME_LORA_UPDATING=True) or fix the route.')];
        }

        return [CheckResult::pass(implode(' · ', $lines))];
    }

    private function routedModels(VllmDoctorContext $context): array
    {
        $routes = [];

        foreach ((array) config('ai.features') as $feature => $settings) {
            if (($settings['provider'] ?? null) !== $context->providerName) {
                continue;
            }

            if (blank($settings['model'] ?? null)) {
                continue;
            }

            $routes[$feature] = (string) $settings['model'];
        }

        return $routes;
    }

    private function describe(string $feature, string $model, array $card): string
    {
        $parent = $card['parent'] ?? null;

        if (! is_string($parent)) {
            return "{$feature} -> {$model}";
        }

        return "{$feature} -> {$model} (LoRA adapter on {$parent})";
    }
}
