<?php

namespace App\Ai\Doctor\Triton\Checks;

use App\Ai\Doctor\Checks\LicenseCheck as ProviderLicenseCheck;
use App\Ai\Doctor\Triton\TritonCheck;
use App\Ai\Doctor\Triton\TritonDoctorContext;

final class LicenseCheck implements TritonCheck
{
    public function run(TritonDoctorContext $context): array
    {
        return ProviderLicenseCheck::judge([
            $context->providerName => $context->providerConfig,
        ]);
    }
}
