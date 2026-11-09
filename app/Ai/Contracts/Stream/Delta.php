<?php

namespace App\Ai\Contracts\Stream;

final readonly class Delta implements StreamEvent
{
    public function __construct(public string $text) {}
}
