<?php

namespace App\Ai\Tools;

final readonly class GateDecision
{
    private function __construct(
        public bool $allowed,
        public string $reason = '',
        public bool $audit = false,
        public bool $needsApproval = false,
        public string $summary = '',
    ) {}

    public static function allow(bool $audit = false): self
    {
        return new self(
            allowed: true,
            audit: $audit,
        );
    }

    public static function deny(string $reason): self
    {
        return new self(
            allowed: false,
            reason: $reason,
        );
    }

    public static function requireApproval(string $summary): self
    {
        return new self(
            allowed: true,
            needsApproval: true,
            summary: $summary,
        );
    }
}
