<?php

namespace App\Ai\Doctor;

use App\Ai\Doctor\Checks\ContextWindowCheck;
use App\Ai\Doctor\Checks\KeepAliveCheck;
use App\Ai\Doctor\Checks\LicenseCheck;
use App\Ai\Doctor\Checks\ModelsPresentCheck;
use App\Ai\Doctor\Checks\ParallelSlotsCheck;
use App\Ai\Doctor\Checks\PlacementCheck;
use App\Ai\Doctor\Checks\PortExposureCheck;
use App\Ai\Doctor\Checks\ReachableCheck;
use App\Ai\Doctor\Checks\RoundTripCheck;

final class Doctor
{
    public function __construct(private readonly array $checks) {}

    public static function standard(): self
    {
        return new self([
            new ReachableCheck,
            new ModelsPresentCheck,
            new ContextWindowCheck,
            new PlacementCheck,
            new ParallelSlotsCheck,
            new KeepAliveCheck,
            new PortExposureCheck,
            new RoundTripCheck,
            new LicenseCheck,
        ]);
    }

    public function run(DoctorContext $context): array
    {
        $results = [];

        foreach ($this->checks as $check) {
            $produced = $check->run($context);

            $results = [...$results, ...$produced];

            if ($check instanceof ReachableCheck && $this->failed($produced)) {
                break;
            }
        }

        return $results;
    }

    private function failed(array $results): bool
    {
        foreach ($results as $result) {
            if ($result->status === CheckStatus::Fail) {
                return true;
            }
        }

        return false;
    }
}
