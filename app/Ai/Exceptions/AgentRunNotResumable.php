<?php

namespace App\Ai\Exceptions;

use RuntimeException;

final class AgentRunNotResumable extends RuntimeException
{
    public function __construct(public readonly int $runId, public readonly string $status)
    {
        parent::__construct("Agent run [{$runId}] is {$status}; only a failed run can be resumed.");
    }
}
