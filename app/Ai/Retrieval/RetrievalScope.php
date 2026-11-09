<?php

namespace App\Ai\Retrieval;

final readonly class RetrievalScope
{
    public function __construct(
        public ?int $tenantId = null,
        public string $plan = '',
        public string $area = '',
    ) {}
}
