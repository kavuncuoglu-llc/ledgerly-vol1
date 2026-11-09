<?php

namespace App\Ai\Doctor\Triton\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Triton\TritonCheck;
use App\Ai\Doctor\Triton\TritonDoctorContext;

final class CapabilitiesCheck implements TritonCheck
{
    public function run(TritonDoctorContext $context): array
    {
        $declared = (array) ($context->providerConfig['capabilities'] ?? []);

        if (in_array('structured', $declared, true)) {
            return [CheckResult::fail("provider {$context->providerName} declares the structured capability, but the Triton OpenAI frontend does not apply response_format (it is excluded from the sampling parameters it forwards): output is not constrained. Remove structured from the provider's capabilities so StructuredClient validates and repairs instead.")];
        }

        return [CheckResult::pass('response_format is not declared as a capability: structured output goes through validation and one repair turn; tool_choice with a named or required tool is the frontend\'s constrained path')];
    }
}
