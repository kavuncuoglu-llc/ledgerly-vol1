<?php

namespace App\Ai\Support;

use App\Ai\Prompts\TicketClassifyPrompt;
use App\Ai\Schemas\TicketData;
use App\Ai\Structured\StructuredClient;
use App\Enums\TicketCategory;

final class ClassifyTicket
{
    public function __construct(private readonly StructuredClient $structured) {}

    public function __invoke(string $message): TicketCategory
    {
        return $this->structured->extract(
            class: TicketData::class,
            messages: TicketClassifyPrompt::messages($message),
            feature: 'ticket.classify',
            promptVersion: TicketClassifyPrompt::VERSION,
        )->category;
    }
}
