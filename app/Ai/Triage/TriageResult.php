<?php

namespace App\Ai\Triage;

use App\Ai\Schemas\TicketData;
use App\Ai\Support\Route;
use App\Models\Ticket;

final readonly class TriageResult
{
    public function __construct(
        public Ticket $ticket,
        public Route $route,
        public string $reason,
        public bool $urgent,
        public string $assignee,
        public array $findings,
        public string $draft,
        public ?TicketData $fields = null,
        public ?int $agentRunId = null,
    ) {}

    public static function person(Ticket $ticket, Route $route, string $reason): self
    {
        return new self(
            ticket: $ticket,
            route: $route,
            reason: $reason,
            urgent: false,
            assignee: Assignment::HUMAN_REVIEW,
            findings: [],
            draft: '',
        );
    }

    public function isAutomated(): bool
    {
        return $this->reason === '';
    }

    public function fieldsArray(): array
    {
        if ($this->fields === null) {
            return [];
        }

        return [
            'category' => $this->fields->category->value,
            'invoice_number' => $this->fields->invoice_number,
            'customer_email' => $this->fields->customer_email,
            'amount_mentioned' => $this->fields->amount_mentioned,
            'date_mentioned' => $this->fields->date_mentioned,
        ];
    }
}
