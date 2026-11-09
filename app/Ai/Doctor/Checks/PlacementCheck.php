<?php

namespace App\Ai\Doctor\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\DoctorCheck;
use App\Ai\Doctor\DoctorContext;

final class PlacementCheck implements DoctorCheck
{
    public function run(DoctorContext $context): array
    {
        $running = $context->roundTrip()->runningAfterNative;

        if ($running === []) {
            $running = $context->roundTrip()->runningAfterCompatible;
        }

        $results = [];

        foreach ($context->providers as $config) {
            $loaded = $context->loaded((string) ($config['model'] ?? ''), $running);

            if ($loaded === []) {
                continue;
            }

            $results[] = $this->describe($loaded);
        }

        if ($results === []) {
            return [CheckResult::warn('no configured model is listed by /api/ps after the round trip (loaded: '.$context->loadedNames($running).'), so GPU placement could not be read. A model that shares weights and options with an already loaded one is served by that runner and gets no row of its own.')];
        }

        return $results;
    }

    private function describe(array $loaded): CheckResult
    {
        $size = (int) ($loaded['size'] ?? 0);

        $gpu = (int) ($loaded['size_vram'] ?? 0);

        $gib = number_format($size / 1024 ** 3, 1).' GiB';

        if ($size === 0) {
            return CheckResult::warn("{$loaded['name']} loaded, size unknown");
        }

        if ($gpu >= $size) {
            return CheckResult::pass("{$loaded['name']} loaded: 100% GPU ({$gib})");
        }

        $gpuPercent = (int) round($gpu / $size * 100);

        $cpuPercent = 100 - $gpuPercent;

        return CheckResult::warn("{$loaded['name']} loaded: {$gpuPercent}%/{$cpuPercent}% GPU/CPU ({$gib}); the CPU layers set the pace. Fit it entirely, lower num_ctx, or use a smaller quantization.");
    }
}
