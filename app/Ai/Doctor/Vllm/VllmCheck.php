<?php

namespace App\Ai\Doctor\Vllm;

interface VllmCheck
{
    public function run(VllmDoctorContext $context): array;
}
