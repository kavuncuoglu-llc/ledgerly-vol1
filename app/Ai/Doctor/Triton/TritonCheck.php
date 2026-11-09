<?php

namespace App\Ai\Doctor\Triton;

interface TritonCheck
{
    public function run(TritonDoctorContext $context): array;
}
