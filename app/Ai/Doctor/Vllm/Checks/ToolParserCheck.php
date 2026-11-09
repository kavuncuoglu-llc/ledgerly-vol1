<?php

namespace App\Ai\Doctor\Vllm\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Vllm\VllmCheck;
use App\Ai\Doctor\Vllm\VllmDoctorContext;

final class ToolParserCheck implements VllmCheck
{
    public function run(VllmDoctorContext $context): array
    {
        $parser = (string) config('ai.vllm.tool_parser');

        if ($parser === '') {
            return [CheckResult::warn('VLLM_TOOL_PARSER is not declared for this app, and vLLM does not report its tool-call parser. Without --enable-auto-tool-choice and --tool-call-parser a tool call comes back as plain text in message.content with finish_reason stop, and the tool loop never runs. Set VLLM_TOOL_PARSER to the parser the server runs with (llama3_json for Llama 3.1).')];
        }

        return [CheckResult::pass("tool parser {$parser} declared (vLLM refuses to start --enable-auto-tool-choice without one)")];
    }
}
