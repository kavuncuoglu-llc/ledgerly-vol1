<?php

namespace App\Ai\Contracts\Capabilities;

interface SupportsNativeStructuredOutput
{
    public function withNativeStructuredOutput(bool $enabled = true): static;
}
