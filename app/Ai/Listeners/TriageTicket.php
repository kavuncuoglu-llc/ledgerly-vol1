<?php

namespace App\Ai\Listeners;

use App\Ai\Triage\TriageRunner;
use App\Events\TicketReceived;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

final class TriageTicket implements ShouldQueue
{
    public function __construct(private readonly TriageRunner $runner) {}

    public function handle(TicketReceived $event): void
    {
        $this->runner->triage($event->ticket, $event->id);
    }

    public function failed(TicketReceived $event, Throwable $exception): void
    {
        report($exception);
    }
}
