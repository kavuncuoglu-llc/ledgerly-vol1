<?php

namespace App\Ai\Tools\OpenTicket;

use App\Ai\Schema\Describe;
use App\Ai\Tools\InvoiceNumber;
use App\Ai\Tools\ToolArguments;

final readonly class OpenTicketArgs implements ToolArguments
{
    public function __construct(
        #[Describe('One or two sentences for the support team: what the customer reported and what you already checked.')]
        public string $summary,
        #[Describe('Invoice number the problem is about, e.g. 4471 or #4471. Null if no invoice is involved.')]
        public ?string $invoice_number,
    ) {}

    public static function rules(): array
    {
        return [
            'summary' => [
                'required',
                'string',
                'min:10',
                'max:1000',
            ],
            'invoice_number' => [
                'nullable',
                'string',
                'regex:'.InvoiceNumber::PATTERN,
            ],
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            summary: trim($data['summary']),
            invoice_number: $data['invoice_number'] ?? null,
        );
    }
}
