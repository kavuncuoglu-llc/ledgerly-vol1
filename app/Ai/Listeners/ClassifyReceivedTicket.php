<?php

namespace App\Ai\Listeners;

use App\Ai\Exceptions\StructuredOutputFailed;
use App\Ai\Exceptions\StructuredRefusal;
use App\Ai\Support\ClassifyTicket;
use App\Enums\TicketCategory;
use App\Events\TicketReceived;
use Illuminate\Support\Facades\Log;

final class ClassifyReceivedTicket
{
    public function __construct(private readonly ClassifyTicket $classify) {}

    public function handle(TicketReceived $event): void
    {
        $event->ticket->update([
            'category' => $this->categoryFor($event),
        ]);
    }

    private function categoryFor(TicketReceived $event): TicketCategory
    {
        try {
            return ($this->classify)($event->ticket->body);
        } catch (StructuredOutputFailed|StructuredRefusal $exception) {
            Log::warning('Ticket classification escalated to the default category', [
                'ticket' => $event->ticket->number,
                'reason' => $exception->getMessage(),
            ]);

            return TicketCategory::Other;
        }
    }
}
