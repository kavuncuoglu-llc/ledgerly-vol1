<?php

namespace App\Ai\Tools\OpenTicket;

use App\Ai\Tools\DtoBackedTool;
use App\Ai\Tools\InvoiceNumber;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;
use App\Enums\TicketStatus;
use App\Models\Invoice;
use App\Models\Ticket;
use Illuminate\Support\Str;

final class OpenTicket implements Tool
{
    use DtoBackedTool;

    public function name(): string
    {
        return 'open_ticket';
    }

    public function arguments(): string
    {
        return OpenTicketArgs::class;
    }

    public function kind(): ToolKind
    {
        return ToolKind::WriteLowRisk;
    }

    public function perConversationLimit(): int
    {
        return 3;
    }

    public function description(): string
    {
        return 'Open a support ticket for the current customer with a short summary of their problem and, if there is one, the invoice number it is about. Returns the ticket number to give to the customer. Use when the customer reports a problem you cannot resolve with the other tools or asks you to log an issue. Do not use for questions you can answer yourself.';
    }

    public function describeIntent(array $arguments, ToolContext $ctx): string
    {
        return 'Open a support ticket: '.($arguments['summary'] ?? '');
    }

    public function handle(object $args, ToolContext $ctx): ToolResult
    {
        $customer = $ctx->customer();

        $invoice = $this->invoiceFor($args, $customer->id);

        if ($args->invoice_number && ! $invoice->exists) {
            return ToolResult::error('invoice not found');
        }

        $ticket = Ticket::firstOrCreate(
            [
                'idempotency_key' => $this->idempotencyKey($ctx, $args),
            ],
            [
                'number' => Ticket::nextNumber(),
                'customer_id' => $customer->id,
                'invoice_id' => $invoice->exists ? $invoice->id : null,
                'subject' => Str::limit($args->summary, 80),
                'body' => $args->summary,
                'status' => TicketStatus::Open,
                'received_at' => now(),
                'source' => $ctx->triageRunId === null
                    ? 'assistant'
                    : 'triage',
            ],
        );

        return ToolResult::ok([
            'ticket_number' => $ticket->number,
            'created' => $ticket->wasRecentlyCreated,
        ]);
    }

    private function invoiceFor(OpenTicketArgs $args, int $customerId): Invoice
    {
        if (! $args->invoice_number) {
            return new Invoice;
        }

        return Invoice::query()
            ->where('customer_id', $customerId)
            ->where('number', InvoiceNumber::normalize($args->invoice_number))
            ->first() ?? new Invoice;
    }

    private function idempotencyKey(ToolContext $ctx, OpenTicketArgs $args): string
    {
        if ($ctx->triageRunId !== null) {
            return $this->triageKey($ctx->triageRunId, $args);
        }

        $conversation = $ctx->conversationId ?? 'none';

        $call = $ctx->toolCallId ?? (string) Str::uuid();

        return "{$ctx->user->id}:{$conversation}:{$call}";
    }

    private function triageKey(int $triageRunId, OpenTicketArgs $args): string
    {
        $fingerprint = mb_strtolower($args->summary).'|'.($args->invoice_number ?? '');

        return "triage:{$triageRunId}:".sha1($fingerprint);
    }
}
