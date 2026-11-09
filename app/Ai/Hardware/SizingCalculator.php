<?php

namespace App\Ai\Hardware;

use InvalidArgumentException;

final class SizingCalculator
{
    private const Q8_CACHE = 'q8_0';

    public function __construct(private readonly ModelSpecs $specs) {}

    public function estimate(
        ModelSpec $spec,
        Hardware $hardware,
        int $context,
        int $slots,
        string $cacheType = 'f16',
        int $reserveBytes = 0,
    ): SizingResult {
        $result = $this->measure($spec, $hardware, $context, $slots, $cacheType, $reserveBytes);

        if ($result->fits()) {
            return $result;
        }

        return $this->withAlternatives($result, $reserveBytes);
    }

    private function measure(
        ModelSpec $spec,
        Hardware $hardware,
        int $context,
        int $slots,
        string $cacheType,
        int $reserveBytes,
    ): SizingResult {
        $cache = $this->cacheBytes($spec, $context, $slots, $cacheType);

        $headroom = $this->headroomBytes($spec->weightsBytes, $cache, $reserveBytes);

        return new SizingResult(
            spec: $spec,
            hardware: $hardware,
            context: $context,
            slots: $slots,
            cacheType: $cacheType,
            weightsBytes: $spec->weightsBytes,
            cacheBytes: $cache,
            cacheBytesAtQ8: $this->cacheBytes($spec, $context, $slots, self::Q8_CACHE),
            headroomBytes: $headroom,
            totalBytes: $spec->weightsBytes + $cache + $headroom,
        );
    }

    private function cacheBytes(ModelSpec $spec, int $context, int $slots, string $cacheType): int
    {
        $bytesPerElement = config('ai.hardware.cache_types')[$cacheType] ?? null;

        if ($bytesPerElement === null) {
            throw new InvalidArgumentException("Unknown cache type [{$cacheType}]. Use one of: ".implode(', ', array_keys(config('ai.hardware.cache_types'))).'.');
        }

        return (int) round($spec->kvBytesPerToken($bytesPerElement) * $context * $slots);
    }

    private function headroomBytes(int $weights, int $cache, int $reserveBytes): int
    {
        return (int) round(($weights + $cache) * (float) config('ai.hardware.headroom_pct')) + $reserveBytes;
    }

    private function withAlternatives(SizingResult $base, int $reserveBytes): SizingResult
    {
        $candidates = array_filter([
            $this->cacheAlternative($base, $reserveBytes),
            $this->quantizationAlternative($base, $reserveBytes),
            $this->contextAlternative($base, $reserveBytes),
            $this->slotsAlternative($base, $reserveBytes),
        ]);

        return new SizingResult(
            spec: $base->spec,
            hardware: $base->hardware,
            context: $base->context,
            slots: $base->slots,
            cacheType: $base->cacheType,
            weightsBytes: $base->weightsBytes,
            cacheBytes: $base->cacheBytes,
            cacheBytesAtQ8: $base->cacheBytesAtQ8,
            headroomBytes: $base->headroomBytes,
            totalBytes: $base->totalBytes,
            alternatives: array_values($candidates),
        );
    }

    private function cacheAlternative(SizingResult $base, int $reserveBytes): ?SizingAlternative
    {
        if ($base->cacheType !== 'f16') {
            return null;
        }

        return $this->alternative('quantize the KV cache to q8_0', $this->measure($base->spec, $base->hardware, $base->context, $base->slots, self::Q8_CACHE, $reserveBytes));
    }

    private function quantizationAlternative(SizingResult $base, int $reserveBytes): ?SizingAlternative
    {
        $ladder = $this->specs->quantizationLadder();

        $position = array_search($base->spec->quantization, $ladder, true);

        if ($position === false) {
            return null;
        }

        $next = $ladder[$position + 1] ?? null;

        if ($next === null) {
            return null;
        }

        $weights = (int) round($base->spec->weightsBytes * $this->specs->bytesPerParameter($next) / $this->specs->bytesPerParameter($base->spec->quantization));

        return $this->alternative(
            "drop to {$next}",
            $this->measure($base->spec->withQuantization($next, $weights), $base->hardware, $base->context, $base->slots, $base->cacheType, $reserveBytes),
        );
    }

    private function contextAlternative(SizingResult $base, int $reserveBytes): ?SizingAlternative
    {
        $halved = intdiv($base->context, 2);

        if ($halved < 512) {
            return null;
        }

        return $this->alternative("halve the context to {$halved}", $this->measure($base->spec, $base->hardware, $halved, $base->slots, $base->cacheType, $reserveBytes));
    }

    private function slotsAlternative(SizingResult $base, int $reserveBytes): ?SizingAlternative
    {
        if ($base->slots < 2) {
            return null;
        }

        return $this->alternative('use one slot', $this->measure($base->spec, $base->hardware, $base->context, 1, $base->cacheType, $reserveBytes));
    }

    private function alternative(string $change, SizingResult $result): SizingAlternative
    {
        return new SizingAlternative(
            change: $change,
            totalBytes: $result->totalBytes,
            fits: $result->fits(),
        );
    }
}
