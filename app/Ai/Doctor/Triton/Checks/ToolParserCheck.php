<?php

namespace App\Ai\Doctor\Triton\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Triton\TritonCheck;
use App\Ai\Doctor\Triton\TritonDoctorContext;

final class ToolParserCheck implements TritonCheck
{
    public function run(TritonDoctorContext $context): array
    {
        $parser = (string) config('ai.triton.tool_parser');

        if ($parser === '') {
            return [CheckResult::warn('TRITON_TOOL_PARSER is not declared. The frontend rejects every request that carries tools with HTTP 400 ("having tools in the request requires --tool-call-parser to be set"), which breaks the tool loop. Start it with --tool-call-parser llama3 (or mistral) and declare the same value here.')];
        }

        return [CheckResult::pass("tool parser declared: {$parser} (the frontend does not report it)")];
    }
}
