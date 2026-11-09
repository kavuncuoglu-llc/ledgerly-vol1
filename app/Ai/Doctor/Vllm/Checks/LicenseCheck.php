<?php

namespace App\Ai\Doctor\Vllm\Checks;

use App\Ai\Doctor\Checks\LicenseCheck as ProviderLicenseCheck;
use App\Ai\Doctor\Vllm\VllmCheck;
use App\Ai\Doctor\Vllm\VllmDoctorContext;

final class LicenseCheck implements VllmCheck
{
    public function run(VllmDoctorContext $context): array
    {
        return ProviderLicenseCheck::judge([
            $context->providerName => $context->providerConfig,
        ]);
    }
}
