<?php

namespace App\Ai\Tools\AccountStatus;

use App\Ai\Tools\CompactsResult;
use App\Ai\Tools\DtoBackedTool;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;
use App\Enums\InvoiceStatus;
use App\Enums\TicketStatus;
use App\Models\Customer;

final class AccountStatus implements CompactsResult, Tool
{
    use DtoBackedTool {
        validate as validateStrictly;
    }

    public function name(): string
    {
        return 'account_status';
    }

    public function arguments(): string
    {
        return AccountStatusArgs::class;
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function description(): string
    {
        return 'Get the current customer\'s account standing: plan, late-fee setting, how many invoices are outstanding or overdue and the oldest overdue age, and optionally the number of open support tickets. Use for account and access questions. Do not use to look up a specific invoice; use lookup_invoice for that.';
    }

    public function validate(array $arguments): array
    {
        return $this->validateStrictly($this->withBooleanLiterals($arguments));
    }

    public function handle(object $args, ToolContext $ctx): ToolResult
    {
        $customer = $ctx->customer();

        $status = [
            'plan' => $customer->plan,
            'currency' => $customer->currency,
            'late_fee_enabled' => $customer->late_fee_enabled,
            'outstanding_invoices' => $this->countWithStatus($customer, InvoiceStatus::Sent),
            'overdue_invoices' => $this->countWithStatus($customer, InvoiceStatus::Overdue),
            'oldest_overdue_days' => $this->oldestOverdueDays($customer),
        ];

        if ($args->include_open_tickets === false) {
            return ToolResult::ok($status);
        }

        return ToolResult::ok([
            ...$status,
            'open_tickets' => $customer->tickets()->where('status', '!=', TicketStatus::Resolved)->count(),
        ]);
    }

    public function compactResult(string $content): string
    {
        $status = json_decode($content, true);

        if (! is_array($status)) {
            return 'no account status returned';
        }

        $summary = sprintf(
            'plan %s; %d overdue invoices (oldest %d days); %d outstanding',
            $status['plan'] ?? 'unknown',
            $status['overdue_invoices'] ?? 0,
            $status['oldest_overdue_days'] ?? 0,
            $status['outstanding_invoices'] ?? 0,
        );

        if (! isset($status['open_tickets'])) {
            return $summary;
        }

        return $summary.'; '.$status['open_tickets'].' open tickets';
    }

    private function withBooleanLiterals(array $arguments): array
    {
        if (! array_key_exists('include_open_tickets', $arguments)) {
            return $arguments;
        }

        $arguments['include_open_tickets'] = match ($arguments['include_open_tickets']) {
            'true' => true,
            'false' => false,
            default => $arguments['include_open_tickets'],
        };

        return $arguments;
    }

    private function countWithStatus(Customer $customer, InvoiceStatus $status): int
    {
        return $customer->invoices()->where('status', $status)->count();
    }

    private function oldestOverdueDays(Customer $customer): int
    {
        $oldest = $customer->invoices()
            ->where('status', InvoiceStatus::Overdue)
            ->orderBy('due_at')
            ->first();

        return $oldest?->daysOverdue() ?? 0;
    }
}
