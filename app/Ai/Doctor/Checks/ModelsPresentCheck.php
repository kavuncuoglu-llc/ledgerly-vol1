<?php

namespace App\Ai\Doctor\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\DoctorCheck;
use App\Ai\Doctor\DoctorContext;

final class ModelsPresentCheck implements DoctorCheck
{
    public function run(DoctorContext $context): array
    {
        $present = [];

        $missing = [];

        foreach ($context->providers as $name => $config) {
            $model = (string) ($config['model'] ?? '');

            $installed = $context->installedModel($model);

            if ($installed === []) {
                $missing[] = "{$name} ({$model}): ollama pull {$model}, or ollama create it from ops/ollama";

                continue;
            }

            $present[] = $this->describe($model, $installed);
        }

        $results = [];

        if ($present !== []) {
            $results[] = CheckResult::pass('models present: '.implode(', ', $present));
        }

        foreach ($missing as $line) {
            $results[] = CheckResult::fail("model missing for provider {$line}");
        }

        return $results;
    }

    private function describe(string $model, array $installed): string
    {
        $details = $installed['details'] ?? [];

        $digest = substr((string) ($installed['digest'] ?? ''), 0, 12);

        return sprintf(
            '%s (%s, %s, %s)',
            $model,
            $details['quantization_level'] ?? 'unknown quantization',
            $details['parameter_size'] ?? 'unknown size',
            $digest,
        );
    }
}
