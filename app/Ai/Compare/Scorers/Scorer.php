<?php

namespace App\Ai\Compare\Scorers;

use App\Ai\Compare\Score;
use App\Ai\Contracts\ChatResponse;

interface Scorer
{
    public function name(): string;

    public function score(ChatResponse $response): Score;
}
