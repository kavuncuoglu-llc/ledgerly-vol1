<?php

namespace App\Ai\Doctor\Triton\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Triton\TritonCheck;
use App\Ai\Doctor\Triton\TritonDoctorContext;

final class ImageCheck implements TritonCheck
{
    private const RELEASE_TAG = '/^\d{2}\.\d{2}-[a-z0-9-]+$/';

    public function run(TritonDoctorContext $context): array
    {
        $tag = (string) config('ai.triton.image_tag');

        if ($tag === '') {
            return [CheckResult::warn('TRITON_TAG is not declared for this app, and the OpenAI frontend does not report which container release it runs. Set it to the nvcr.io/nvidia/tritonserver tag Compose starts, so a changed server shows up in a diff.')];
        }

        if (preg_match(self::RELEASE_TAG, $tag) !== 1) {
            return [CheckResult::warn("image tag {$tag} is not a release tag such as 26.10-vllm-python-py3: pin one, because latest-style tags move under you.")];
        }

        return [CheckResult::pass("image tag pinned: {$tag}")];
    }
}
