<?php

namespace App\Ai\Doctor;

final readonly class RoundTripOutcome
{
    public function __construct(
        public array $compatible,
        public array $native,
        public array $runningAfterCompatible,
        public array $runningAfterNative,
    ) {}
}
