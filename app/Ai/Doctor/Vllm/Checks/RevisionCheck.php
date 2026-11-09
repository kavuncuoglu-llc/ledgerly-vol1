<?php

namespace App\Ai\Doctor\Vllm\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Vllm\VllmCheck;
use App\Ai\Doctor\Vllm\VllmDoctorContext;

final class RevisionCheck implements VllmCheck
{
    public function run(VllmDoctorContext $context): array
    {
        $revision = (string) config('ai.vllm.revision');

        if ($revision === '') {
            return [CheckResult::warn('VLLM_REVISION is not declared for this app, and vLLM does not report which revision of the weights it loaded. Set it to the commit sha the server runs with (--revision), so a changed model shows up in a diff.')];
        }

        if (preg_match('/^[0-9a-f]{40}$/', $revision) !== 1) {
            return [CheckResult::warn("revision {$revision} is not a 40-character commit sha: a branch or tag name can move under you. Pin the commit.")];
        }

        return [CheckResult::pass('revision pinned: '.substr($revision, 0, 12))];
    }
}
