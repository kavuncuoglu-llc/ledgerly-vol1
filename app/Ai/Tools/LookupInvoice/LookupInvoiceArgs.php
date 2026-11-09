<?php

namespace App\Ai\Tools\LookupInvoice;

use App\Ai\Schema\Describe;
use App\Ai\Tools\InvoiceNumber;
use App\Ai\Tools\ToolArguments;

final readonly class LookupInvoiceArgs implements ToolArguments
{
    public function __construct(
        #[Describe('Invoice number as the customer wrote it, e.g. 4471 or #4471. Null if they gave a month instead.')]
        public ?string $invoice_number,
        #[Describe('Month the customer referred to, as YYYY-MM. Null if they gave an invoice number.')]
        public ?string $month,
    ) {}

    public static function rules(): array
    {
        return [
            'invoice_number' => [
                'nullable',
                'string',
                'required_without:month',
                'regex:'.InvoiceNumber::PATTERN,
            ],
            'month' => [
                'nullable',
                'string',
                'required_without:invoice_number',
                'date_format:Y-m',
            ],
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            invoice_number: $data['invoice_number'] ?? null,
            month: $data['month'] ?? null,
        );
    }
}
