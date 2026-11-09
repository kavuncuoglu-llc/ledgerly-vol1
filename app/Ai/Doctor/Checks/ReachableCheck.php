<?php

namespace App\Ai\Doctor\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\DoctorCheck;
use App\Ai\Doctor\DoctorContext;

final class ReachableCheck implements DoctorCheck
{
    public function run(DoctorContext $context): array
    {
        $version = $context->client->version();

        if ($version === '') {
            return [CheckResult::fail("Ollama at {$context->client->baseUrl()} is not reachable. Is it running?")];
        }

        return [CheckResult::pass("Ollama at {$context->client->baseUrl()} reachable; version {$version}")];
    }
}
