<?php

namespace App\Ai\Schemas;

use App\Ai\Schema\Describe;

final readonly class LineItem
{
    public function __construct(
        #[Describe('What was sold, as printed on the line')]
        public string $description,
        #[Describe('Quantity, a plain number')]
        public float $quantity,
        #[Describe('Price of one unit in the invoice currency, a plain number')]
        public float $unit_price,
        #[Describe('Line amount in the invoice currency, a plain number')]
        public float $amount,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            description: (string) $data['description'],
            quantity: (float) $data['quantity'],
            unit_price: (float) $data['unit_price'],
            amount: (float) $data['amount'],
        );
    }
}
