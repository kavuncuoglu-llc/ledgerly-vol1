<?php

namespace App\Ai\Hardware;

final readonly class ModelSpec
{
    public function __construct(
        public string $name,
        public int $weightsBytes,
        public int $layers,
        public int $kvHeads,
        public int $headDim,
        public string $quantization,
        public string $source,
    ) {}

    public function kvBytesPerToken(float $bytesPerElement): float
    {
        return 2 * $this->layers * $this->kvHeads * $this->headDim * $bytesPerElement;
    }

    public function withQuantization(string $quantization, int $weightsBytes): self
    {
        return new self(
            name: $this->name,
            weightsBytes: $weightsBytes,
            layers: $this->layers,
            kvHeads: $this->kvHeads,
            headDim: $this->headDim,
            quantization: $quantization,
            source: $this->source,
        );
    }
}
