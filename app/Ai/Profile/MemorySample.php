<?php

namespace App\Ai\Profile;

final readonly class MemorySample
{
    public function __construct(
        public float $at,
        public int $residentBytes,
        public int $gpuBytes,
        public int $spilledBytes,
        public bool $modelLoaded,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            at: (float) $data['at'],
            residentBytes: (int) $data['resident'],
            gpuBytes: (int) $data['gpu'],
            spilledBytes: (int) $data['spilled'],
            modelLoaded: (bool) $data['loaded'],
        );
    }

    public function toArray(): array
    {
        return [
            'at' => $this->at,
            'resident' => $this->residentBytes,
            'gpu' => $this->gpuBytes,
            'spilled' => $this->spilledBytes,
            'loaded' => $this->modelLoaded,
        ];
    }
}
