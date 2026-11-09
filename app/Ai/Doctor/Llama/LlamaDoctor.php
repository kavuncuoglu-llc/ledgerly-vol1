<?php

namespace App\Ai\Doctor\Llama;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\CheckStatus;
use App\Ai\Doctor\Llama\Checks\AuthCheck;
use App\Ai\Doctor\Llama\Checks\GenerationCheck;
use App\Ai\Doctor\Llama\Checks\GrammarCheck;
use App\Ai\Doctor\Llama\Checks\HealthCheck;
use App\Ai\Doctor\Llama\Checks\LicenseCheck;
use App\Ai\Doctor\Llama\Checks\MetricsCheck;
use App\Ai\Doctor\Llama\Checks\OffloadCheck;
use App\Ai\Doctor\Llama\Checks\SlotContextCheck;
use App\Ai\Doctor\Llama\Checks\SlotsCheck;
use App\Ai\Doctor\Llama\Checks\TokenizeCheck;

final class LlamaDoctor
{
    public function __construct(private readonly array $checks) {}

    public static function standard(): self
    {
        return new self([
            new HealthCheck,
            new SlotsCheck,
            new SlotContextCheck,
            new OffloadCheck,
            new MetricsCheck,
            new AuthCheck,
            new TokenizeCheck,
            new GenerationCheck,
            new GrammarCheck,
            new LicenseCheck,
        ]);
    }

    public function run(LlamaDoctorContext $context): array
    {
        $results = [];

        foreach ($this->checks as $check) {
            $produced = $check->run($context);

            $results = [...$results, ...$produced];

            if ($check instanceof HealthCheck && $this->failed($produced)) {
                break;
            }
        }

        return $results;
    }

    private function failed(array $results): bool
    {
        foreach ($results as $result) {
            if ($result instanceof CheckResult && $result->status === CheckStatus::Fail) {
                return true;
            }
        }

        return false;
    }
}
