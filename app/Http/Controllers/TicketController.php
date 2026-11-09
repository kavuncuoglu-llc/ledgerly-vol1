<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Contracts\View\View;

class TicketController extends Controller
{
    public function index(): View
    {
        $tickets = Ticket::query()
            ->with('customer')
            ->orderByDesc('received_at')
            ->paginate(25);

        return view('tickets.index', [
            'tickets' => $tickets,
        ]);
    }

    public function show(Ticket $ticket): View
    {
        $ticket->load([
            'customer',
            'invoice',
        ]);

        return view('tickets.show', [
            'ticket' => $ticket,
        ]);
    }
}
