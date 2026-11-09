<?php

namespace App\Ai\Tools\ListInvoices;

use App\Ai\Schema\Describe;
use App\Ai\Tools\ToolArguments;
use App\Enums\InvoiceStatus;
use Illuminate\Validation\Rule;

final readonly class ListInvoicesArgs implements ToolArguments
{
    public function __construct(
        #[Describe('Only invoices with this status. Null for every status.')]
        public ?InvoiceStatus $status,
        #[Describe('Only invoices issued in this month, as YYYY-MM. Null for every month.')]
        public ?string $month,
        #[Describe('How many months back to look when no month is given. Null for the default of 6.')]
        public ?int $months = null,
    ) {}

    public static function rules(): array
    {
        return [
            'status' => [
                'nullable',
                Rule::enum(InvoiceStatus::class),
            ],
            'month' => [
                'nullable',
                'string',
                'date_format:Y-m',
            ],
            'months' => [
                'nullable',
                'integer',
                'between:1,24',
            ],
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            status: InvoiceStatus::tryFrom((string) ($data['status'] ?? '')),
            month: $data['month'] ?? null,
            months: isset($data['months'])
                ? (int) $data['months']
                : null,
        );
    }
}
