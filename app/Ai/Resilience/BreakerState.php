<?php

namespace App\Ai\Resilience;

use Carbon\CarbonImmutable;

final readonly class BreakerState
{
    public const CLOSED = 'closed';

    public const OPEN = 'open';

    public const HALF_OPEN = 'half-open';

    public function __construct(
        public string $status,
        public int $failures,
        public ?CarbonImmutable $openedAt,
        public ?CarbonImmutable $retryAt,
    ) {}

    public static function closed(int $failures = 0): self
    {
        return new self(self::CLOSED, $failures, null, null);
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function describe(int $threshold): string
    {
        if ($this->status === self::CLOSED) {
            return 'closed';
        }

        $opened = $this->openedAt?->format('H:i:s');

        $retry = $this->retryAt?->format('H:i:s');

        if ($this->status === self::OPEN) {
            return "OPEN ({$threshold} consecutive ProviderUnavailable at {$opened}; retry at {$retry})";
        }

        return "half-open (opened at {$opened}; the next call is the trial)";
    }
}
