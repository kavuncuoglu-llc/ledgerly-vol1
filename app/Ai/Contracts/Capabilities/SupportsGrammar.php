<?php

namespace App\Ai\Contracts\Capabilities;

interface SupportsGrammar
{
    public function withGrammar(string $grammar): static;
}
