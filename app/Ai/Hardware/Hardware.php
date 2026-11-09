<?php

namespace App\Ai\Hardware;

final readonly class Hardware
{
    public function __construct(
        public string $label,
        public MemoryKind $kind,
        public int $totalBytes,
        public int $reachableBytes,
        public string $reachableNote,
        public float $bandwidthGbps = 0.0,
    ) {}

    public function withReachable(int $reachableBytes, string $note): self
    {
        return new self(
            label: $this->label,
            kind: $this->kind,
            totalBytes: $this->totalBytes,
            reachableBytes: $reachableBytes,
            reachableNote: $note,
            bandwidthGbps: $this->bandwidthGbps,
        );
    }

    public function withBandwidth(float $bandwidthGbps): self
    {
        return new self(
            label: $this->label,
            kind: $this->kind,
            totalBytes: $this->totalBytes,
            reachableBytes: $this->reachableBytes,
            reachableNote: $this->reachableNote,
            bandwidthGbps: $bandwidthGbps,
        );
    }

    public function isDetected(): bool
    {
        return $this->reachableBytes > 0;
    }
}
