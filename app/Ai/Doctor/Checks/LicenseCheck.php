<?php

namespace App\Ai\Doctor\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\DoctorCheck;
use App\Ai\Doctor\DoctorContext;

final class LicenseCheck implements DoctorCheck
{
    public function run(DoctorContext $context): array
    {
        $unrecorded = [];

        $unreviewed = [];

        foreach ($context->providers as $name => $config) {
            if (blank($config['license'] ?? null)) {
                $unrecorded[] = $name;

                continue;
            }

            if (blank($config['license_reviewed'] ?? null)) {
                $unreviewed[] = $name;
            }
        }

        if ($unrecorded !== []) {
            return [CheckResult::fail('no license recorded for provider '.implode(', ', $unrecorded).': set its license in config/ai.php')];
        }

        if ($unreviewed !== []) {
            return [CheckResult::warn('license recorded for all providers, not yet reviewed for '.implode(', ', $unreviewed).' (AI_LICENSE_REVIEWED)')];
        }

        return [CheckResult::pass('license recorded and reviewed for all providers')];
    }
}
