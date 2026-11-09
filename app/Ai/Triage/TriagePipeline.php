<?php

namespace App\Ai\Triage;

use App\Ai\Agents\AgentRunner;
use App\Ai\Agents\AgentState;
use App\Ai\Agents\InvestigateAgent;
use App\Ai\Exceptions\StructuredOutputFailed;
use App\Ai\Exceptions\StructuredRefusal;
use App\Ai\Prompts\TicketExtractPrompt;
use App\Ai\Schemas\TicketData;
use App\Ai\Structured\StructuredClient;
use App\Ai\Support\Route;
use App\Ai\Support\Router;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRegistry;
use App\Enums\TicketCategory;
use App\Models\AgentRun;
use App\Models\Ticket;
use App\Models\TriageRun;
use Illuminate\Support\Facades\Log;

final class TriagePipeline
{
    public const INVESTIGATED = [
        TicketCategory::Billing,
        TicketCategory::Account,
    ];

    public function __construct(
        private readonly Router $router,
        private readonly StructuredClient $structured,
        private readonly AgentRunner $agents,
        private readonly ToolRegistry $tools,
        private readonly Drafter $drafter,
    ) {}

    public function run(Ticket $ticket, TriageRun $triageRun): TriageResult
    {
        $started = hrtime(true);

        $route = $this->router->route($ticket->body);

        $result = $route->isAutomated()
            ? $this->automate($ticket, $route, $triageRun)
            : TriageResult::person($ticket, $route, $route->reason);

        $this->persist($triageRun, $result, $started);

        return $result;
    }

    private function automate(Ticket $ticket, Route $route, TriageRun $triageRun): TriageResult
    {
        try {
            $fields = $this->extract($ticket);
        } catch (StructuredOutputFailed|StructuredRefusal $exception) {
            Log::warning('Ticket triage escalated: extraction failed', [
                'ticket' => $ticket->number,
                'reason' => $exception->getMessage(),
            ]);

            return TriageResult::person($ticket, $route, 'extraction failed');
        }

        $urgent = UrgencyRule::evaluate($route->category, $fields, $ticket->customer);

        $investigation = $this->investigate($ticket, $route, $fields, $triageRun);

        $findings = $this->findingsOf($investigation);

        return new TriageResult(
            ticket: $ticket,
            route: $route,
            reason: '',
            urgent: $urgent,
            assignee: Assignment::for($route->category, $urgent),
            findings: $findings,
            draft: $this->drafter->draft($ticket, $route->category, $findings, $route->area),
            fields: $fields,
            agentRunId: $investigation->id,
        );
    }

    private function extract(Ticket $ticket): TicketData
    {
        return $this->structured->extract(
            class: TicketData::class,
            messages: TicketExtractPrompt::messages($ticket->body),
            feature: 'ticket.extract',
            promptVersion: TicketExtractPrompt::VERSION,
            sourceText: $ticket->body,
        );
    }

    private function investigate(Ticket $ticket, Route $route, TicketData $fields, TriageRun $triageRun): AgentRun
    {
        if (! in_array($route->category, self::INVESTIGATED, true)) {
            return new AgentRun;
        }

        $owner = $ticket->customer?->owner;

        if ($owner === null) {
            return new AgentRun;
        }

        $result = $this->agents->run(
            new InvestigateAgent(
                category: $route->category,
                tools: $this->tools->only($route->tools),
                budget: config('ai.agents.triage'),
            ),
            AgentState::fresh(
                goal: "Gather the facts needed to resolve: {$ticket->subject}",
                facts: $this->factsFrom($fields),
            ),
            new ToolContext($owner, triageRunId: $triageRun->id),
        );

        return AgentRun::query()->findOrFail($result->runId);
    }

    private function findingsOf(AgentRun $investigation): array
    {
        if (! $investigation->exists) {
            return [];
        }

        return AgentState::fromArray($investigation->state)->findings();
    }

    private function factsFrom(TicketData $fields): array
    {
        return array_filter([
            'invoice_number' => $fields->invoice_number,
            'customer_email' => $fields->customer_email,
            'amount_mentioned' => $fields->amount_mentioned,
            'date_mentioned' => $fields->date_mentioned,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function persist(TriageRun $triageRun, TriageResult $result, int|float $started): void
    {
        $this->labelTicket($result);

        $triageRun->update([
            'status' => $result->isAutomated()
                ? TriageRun::STATUS_QUEUED_FOR_REVIEW
                : TriageRun::STATUS_HUMAN,
            'category' => $result->route->category->value,
            'agreement' => $result->route->agreement,
            'votes' => $result->route->votes,
            'reason' => $result->reason === ''
                ? null
                : $result->reason,
            'fields' => $result->fieldsArray(),
            'urgent' => $result->urgent,
            'assignee' => $result->assignee,
            'findings' => $result->findings,
            'draft' => $result->draft === ''
                ? null
                : $result->draft,
            'agent_run_id' => $result->agentRunId,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ]);
    }

    private function labelTicket(TriageResult $result): void
    {
        $attributes = [
            'assignee' => $result->assignee,
            'urgent' => $result->urgent,
        ];

        if (! $result->route->isHuman()) {
            $attributes['category'] = $result->route->category;
        }

        $result->ticket->update($attributes);
    }
}
