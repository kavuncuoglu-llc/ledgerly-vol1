<?php

namespace App\Ai\Tools;

interface CompactsResult
{
    public function compactResult(string $content): string;
}
