<?php

namespace App\Ai\Doctor\Triton;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\CheckStatus;
use App\Ai\Doctor\Triton\Checks\AuthCheck;
use App\Ai\Doctor\Triton\Checks\CapabilitiesCheck;
use App\Ai\Doctor\Triton\Checks\GenerationCheck;
use App\Ai\Doctor\Triton\Checks\HealthCheck;
use App\Ai\Doctor\Triton\Checks\ImageCheck;
use App\Ai\Doctor\Triton\Checks\LicenseCheck;
use App\Ai\Doctor\Triton\Checks\MetricsCheck;
use App\Ai\Doctor\Triton\Checks\ModelConfigCheck;
use App\Ai\Doctor\Triton\Checks\RepositoryCheck;
use App\Ai\Doctor\Triton\Checks\ServedModelCheck;
use App\Ai\Doctor\Triton\Checks\ToolParserCheck;

final class TritonDoctor
{
    public function __construct(private readonly array $checks) {}

    public static function standard(): self
    {
        return new self([
            new HealthCheck,
            new ImageCheck,
            new ServedModelCheck,
            new RepositoryCheck,
            new ModelConfigCheck,
            new AuthCheck,
            new ToolParserCheck,
            new CapabilitiesCheck,
            new MetricsCheck,
            new GenerationCheck,
            new LicenseCheck,
        ]);
    }

    public function run(TritonDoctorContext $context): array
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
