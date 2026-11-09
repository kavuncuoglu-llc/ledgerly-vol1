<?php

namespace App\Ai\Doctor\Vllm;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\CheckStatus;
use App\Ai\Doctor\Vllm\Checks\AdaptersCheck;
use App\Ai\Doctor\Vllm\Checks\AuthCheck;
use App\Ai\Doctor\Vllm\Checks\CapacityCheck;
use App\Ai\Doctor\Vllm\Checks\ConcurrencyCheck;
use App\Ai\Doctor\Vllm\Checks\GenerationCheck;
use App\Ai\Doctor\Vllm\Checks\HealthCheck;
use App\Ai\Doctor\Vllm\Checks\LicenseCheck;
use App\Ai\Doctor\Vllm\Checks\MetricsCheck;
use App\Ai\Doctor\Vllm\Checks\ModelLengthCheck;
use App\Ai\Doctor\Vllm\Checks\PrefixCacheCheck;
use App\Ai\Doctor\Vllm\Checks\RevisionCheck;
use App\Ai\Doctor\Vllm\Checks\ServedModelCheck;
use App\Ai\Doctor\Vllm\Checks\StructuredOutputCheck;
use App\Ai\Doctor\Vllm\Checks\ToolParserCheck;

final class VllmDoctor
{
    public function __construct(private readonly array $checks) {}

    public static function standard(): self
    {
        return new self([
            new HealthCheck,
            new RevisionCheck,
            new ServedModelCheck,
            new ModelLengthCheck,
            new AdaptersCheck,
            new CapacityCheck,
            new ConcurrencyCheck,
            new PrefixCacheCheck,
            new AuthCheck,
            new ToolParserCheck,
            new MetricsCheck,
            new GenerationCheck,
            new StructuredOutputCheck,
            new LicenseCheck,
        ]);
    }

    public function run(VllmDoctorContext $context): array
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
