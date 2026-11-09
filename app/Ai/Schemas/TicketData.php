<?php

namespace App\Ai\Schemas;

use App\Ai\Schema\Describe;
use App\Ai\Structured\ExtractionMeta;
use App\Ai\Structured\StructuredData;
use App\Enums\TicketCategory;
use Illuminate\Validation\Rule;

final readonly class TicketData implements StructuredData
{
    public function __construct(
        #[Describe('Category of the message')]
        public TicketCategory $category,
        #[Describe('Invoice number mentioned in the message, digits as written; null if none')]
        public ?string $invoice_number,
        #[Describe('Email address mentioned in the message; null if none')]
        public ?string $customer_email,
        #[Describe('Money amount mentioned, a plain positive number; null if none')]
        public ?float $amount_mentioned,
        #[Describe('Date mentioned, ISO 8601 (YYYY-MM-DD); null if no year is stated')]
        public ?string $date_mentioned,
        public ExtractionMeta $meta = new ExtractionMeta,
    ) {}

    public static function fromArray(array $data, ExtractionMeta $meta = new ExtractionMeta): static
    {
        return new self(
            category: TicketCategory::from($data['category']),
            invoice_number: $data['invoice_number'] ?? null,
            customer_email: $data['customer_email'] ?? null,
            amount_mentioned: isset($data['amount_mentioned'])
                ? (float) $data['amount_mentioned']
                : null,
            date_mentioned: $data['date_mentioned'] ?? null,
            meta: $meta,
        );
    }

    public static function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(TicketCategory::class)],
            'invoice_number' => ['nullable', 'string', 'max:64'],
            'customer_email' => ['nullable', 'string', 'email', 'max:254'],
            'amount_mentioned' => ['nullable', 'numeric', 'min:0'],
            'date_mentioned' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
        ];
    }

    public static function afterRules(string $sourceText = ''): array
    {
        return [];
    }
}
