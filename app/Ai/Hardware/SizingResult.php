<?php

namespace App\Ai\Hardware;

final readonly class SizingResult
{
    public function __construct(
        public ModelSpec $spec,
        public Hardware $hardware,
        public int $context,
        public int $slots,
        public string $cacheType,
        public int $weightsBytes,
        public int $cacheBytes,
        public int $cacheBytesAtQ8,
        public int $headroomBytes,
        public int $totalBytes,
        public array $alternatives = [],
    ) {}

    public function spareBytes(): int
    {
        return $this->hardware->reachableBytes - $this->totalBytes;
    }

    public function fits(): bool
    {
        return $this->hardware->isDetected() && $this->spareBytes() >= 0;
    }

    public function bindingConstraint(): string
    {
        $terms = [
            'weights' => $this->weightsBytes,
            'cache' => $this->cacheBytes,
            'headroom' => $this->headroomBytes,
        ];

        arsort($terms);

        return (string) array_key_first($terms);
    }
}
