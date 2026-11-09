<?php

namespace App\Ai\Contracts\Stream;

use App\Ai\Contracts\ChatResponse;

final readonly class Completed implements StreamEvent
{
    public function __construct(public ChatResponse $response) {}
}
