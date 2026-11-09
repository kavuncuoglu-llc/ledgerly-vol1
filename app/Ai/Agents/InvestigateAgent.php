<?php

namespace App\Ai\Agents;

use App\Ai\Prompts\InvestigatePrompt;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolRegistry;
use App\Ai\Tools\ToolResult;
use App\Enums\TicketCategory;

final class InvestigateAgent implements Agent
{
    public const MAX_TOKENS = 300;

    public function __construct(
        private readonly TicketCategory $category,
        private readonly ToolRegistry $tools,
        private readonly array $budget,
    ) {}

    public function name(): string
    {
        return 'investigate';
    }

    public function feature(): string
    {
        return 'agent.investigate';
    }

    public function promptVersion(): string
    {
        return InvestigatePrompt::VERSION;
    }

    public function budget(): AgentBudget
    {
        return AgentBudget::fromConfig($this->budget);
    }

    public function prompt(AgentState $state): array
    {
        return InvestigatePrompt::messages($state, $this->category, $this->pending($state));
    }

    public function options(): array
    {
        return [
            'profile' => 'deterministic',
            'max_tokens' => self::MAX_TOKENS,
        ];
    }

    public function tools(): ToolRegistry
    {
        return $this->tools;
    }

    public function observe(AgentState $state, array $call, ToolResult $result): AgentState
    {
        $name = CallSignature::name($call);

        $tool = $this->tools->all()->first(fn (Tool $registered): bool => $registered->name() === $name);

        $signature = CallSignature::of($call);

        $step = [
            'tool' => $name,
            'call' => CallSignature::describe($call),
            'summary' => ResultSummary::of($tool, $result),
            'ok' => $result->ok,
        ];

        if ($this->repeatsEarlierStep($state, $signature, $step)) {
            return $state;
        }

        $state = $state->withStep($signature, $step);

        if (! $result->ok) {
            return $state;
        }

        return match ($name) {
            'lookup_invoice' => $this->withInvoice($state, $call, $result),
            'list_invoices' => $state->withFinding('recent_invoices', ResultSummary::of($tool, $result)),
            'account_status' => $state->withFinding('account', $result->data),
            'open_ticket' => $state->withFindingAppended('tickets', (string) ($result->data['ticket_number'] ?? '')),
            default => $state,
        };
    }

    private function repeatsEarlierStep(AgentState $state, string $signature, array $step): bool
    {
        if (! $state->hasStep($signature)) {
            return false;
        }

        $earlier = $state->steps[$signature];

        if ($earlier['summary'] !== $step['summary']) {
            return false;
        }

        return $earlier['ok'] === $step['ok'];
    }

    private function withInvoice(AgentState $state, array $call, ToolResult $result): AgentState
    {
        if (($result->data['found'] ?? false) !== true) {
            return $state->withFindingAppended('invoices_not_found', (string) (CallSignature::arguments($call)['invoice_number'] ?? ''));
        }

        return $state->withFindingAppended('invoices', $result->data['invoice']);
    }

    private function pending(AgentState $state): array
    {
        return array_values(array_filter([
            $this->invoiceLookupPending($state),
            $this->accountCheckPending($state),
        ]));
    }

    private function invoiceLookupPending(AgentState $state): string
    {
        $invoiceNumber = (string) ($state->facts['invoice_number'] ?? '');

        if ($invoiceNumber === '') {
            return '';
        }

        if (! $this->tools->has('lookup_invoice')) {
            return '';
        }

        if ($this->hasRun($state, 'lookup_invoice')) {
            return '';
        }

        return "look up invoice {$invoiceNumber}";
    }

    private function accountCheckPending(AgentState $state): string
    {
        if ($this->category !== TicketCategory::Account) {
            return '';
        }

        if (! $this->tools->has('account_status')) {
            return '';
        }

        if ($this->hasRun($state, 'account_status')) {
            return '';
        }

        return 'check the account status';
    }

    private function hasRun(AgentState $state, string $tool): bool
    {
        foreach ($state->steps as $step) {
            if ($step['tool'] === $tool) {
                return true;
            }
        }

        return false;
    }
}
