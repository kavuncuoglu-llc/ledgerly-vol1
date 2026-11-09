<?php

namespace App\Events;

use App\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;

final class TicketReceived
{
    use Dispatchable;

    public readonly string $id;

    public function __construct(public readonly Ticket $ticket, ?string $id = null)
    {
        $this->id = $id ?? 'ticket:'.$ticket->id;
    }
}
