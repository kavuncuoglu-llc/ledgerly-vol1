<?php

namespace Tests\Support;

use App\Ai\Schema\Describe;
use App\Ai\Tools\ToolArguments;

final readonly class RefundArguments implements ToolArguments
{
    public function __construct(
        #[Describe('Invoice number')]
        public string $invoice,
        #[Describe('Amount to refund')]
        public float $amount,
    ) {}

    public static function rules(): array
    {
        return [
            'invoice' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:0'],
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self($data['invoice'], (float) $data['amount']);
    }
}
