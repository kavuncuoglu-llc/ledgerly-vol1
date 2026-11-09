<?php

namespace App\Ai\Listeners;

use App\Ai\Support\ClassifyTicket;
use App\Enums\TicketCategory;
use App\Events\TicketReceived;

final class ClassifyReceivedTicket
{
    public function __construct(private readonly ClassifyTicket $classify) {}

    public function handle(TicketReceived $event): void
    {
        $label = ($this->classify)($event->ticket->body);

        $event->ticket->update([
            'category' => TicketCategory::from($label),
        ]);
    }
}
