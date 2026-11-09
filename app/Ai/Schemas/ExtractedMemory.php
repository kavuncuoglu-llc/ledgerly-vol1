<?php

namespace App\Ai\Schemas;

use App\Ai\Schema\Describe;

final readonly class ExtractedMemory
{
    public function __construct(
        #[Describe('Short snake_case name of the fact, such as role, preferred_name, timezone, billing_contact')]
        public string $key,
        #[Describe('The fact in the customer\'s own terms, as stated')]
        public string $value,
        #[Describe('How sure you are that the customer stated this directly, from 0 to 1')]
        public float $confidence,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            key: (string) $data['key'],
            value: (string) $data['value'],
            confidence: (float) $data['confidence'],
        );
    }
}
