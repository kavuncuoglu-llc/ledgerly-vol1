<?php

namespace App\Ai\Contracts\Capabilities;

interface SupportsContextWindowOverride
{
    public function withContextWindow(int $tokens): static;
}
