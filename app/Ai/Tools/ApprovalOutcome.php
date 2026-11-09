<?php

namespace App\Ai\Tools;

use App\Models\PendingToolCall;

final readonly class ApprovalOutcome
{
    public function __construct(
        public PendingToolCall $pending,
        public ?ToolRunResult $run = null,
    ) {}
}
