<?php

namespace App\Ai\Parsing;

interface DocumentParser
{
    public function markdown(string $path): string;
}
