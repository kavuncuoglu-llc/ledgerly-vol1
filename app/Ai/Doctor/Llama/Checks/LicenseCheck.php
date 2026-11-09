<?php

namespace App\Ai\Doctor\Llama\Checks;

use App\Ai\Doctor\Checks\LicenseCheck as ProviderLicenseCheck;
use App\Ai\Doctor\Llama\LlamaCheck;
use App\Ai\Doctor\Llama\LlamaDoctorContext;

final class LicenseCheck implements LlamaCheck
{
    public function run(LlamaDoctorContext $context): array
    {
        return ProviderLicenseCheck::judge([
            $context->providerName => $context->providerConfig,
        ]);
    }
}
