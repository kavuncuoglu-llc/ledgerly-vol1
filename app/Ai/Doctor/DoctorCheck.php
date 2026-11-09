<?php

namespace App\Ai\Doctor;

interface DoctorCheck
{
    public function run(DoctorContext $context): array;
}
