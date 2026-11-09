<?php

namespace App\Ai\Doctor\Llama;

interface LlamaCheck
{
    public function run(LlamaDoctorContext $context): array;
}
