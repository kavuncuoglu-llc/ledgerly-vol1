<?php

namespace App\Events;

use App\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;

final class TicketReceived
{
    use Dispatchable;

    public function __construct(public readonly Ticket $ticket) {}
}
