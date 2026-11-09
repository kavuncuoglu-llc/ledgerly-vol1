<?php

namespace App\Ai\Exceptions;

use RuntimeException;

final class SummarizationFailed extends RuntimeException
{
    public function __construct(public readonly int $conversationId)
    {
        parent::__construct("The model returned an empty summary for conversation {$conversationId}; the previous summary was kept.");
    }
}
