<?php

namespace App\Ai\Hardware;

final class HardwareProbe
{
    private const BYTES_PER_MIB = 1_048_576;

    private const BYTES_PER_KIB = 1024;

    public function __construct(private readonly HostInspector $host) {}

    public function detect(): Hardware
    {
        $hardware = match ($this->host->operatingSystem()) {
            'Darwin' => $this->macOs(),
            'Linux' => $this->linux(),
            default => $this->undetected(),
        };

        return $this->withOverrides($hardware);
    }

    private function macOs(): Hardware
    {
        $total = (int) $this->host->run(['sysctl', '-n', 'hw.memsize']);

        $chip = $this->host->run(['sysctl', '-n', 'machdep.cpu.brand_string']) ?: 'Apple silicon';

        $wiredLimitMib = (int) $this->host->run(['sysctl', '-n', 'iogpu.wired_limit_mb']);

        $share = (float) config('ai.hardware.macos_gpu_share');

        $reachable = $wiredLimitMib > 0
            ? $wiredLimitMib * self::BYTES_PER_MIB
            : (int) round($total * $share);

        $note = $wiredLimitMib > 0
            ? "macOS; iogpu.wired_limit_mb = {$wiredLimitMib}"
            : sprintf('macOS; iogpu.wired_limit_mb unset, assuming %d%% of %s', (int) round($share * 100), ByteSize::format($total));

        return new Hardware(
            label: $chip,
            kind: MemoryKind::Unified,
            totalBytes: $total,
            reachableBytes: $reachable,
            reachableNote: $note,
            bandwidthGbps: $this->appleBandwidth($chip),
        );
    }

    private function appleBandwidth(string $chip): float
    {
        $name = trim(str_replace('Apple', '', $chip));

        return (float) (config('ai.hardware.apple_bandwidth_gbps')[$name] ?? 0);
    }

    private function linux(): Hardware
    {
        $nvidia = $this->nvidia();

        if ($nvidia->isDetected()) {
            return $nvidia;
        }

        $amd = $this->amd();

        if ($amd->isDetected()) {
            return $amd;
        }

        return $this->linuxSystemRam();
    }

    private function nvidia(): Hardware
    {
        $output = $this->host->run(['nvidia-smi', '--query-gpu=name,memory.total', '--format=csv,noheader,nounits']);

        $names = [];

        $totalMib = 0;

        foreach (array_filter(explode("\n", $output)) as $line) {
            [$name, $mib] = array_pad(array_map('trim', explode(',', $line)), 2, '');

            $names[] = $name;

            $totalMib += (int) $mib;
        }

        if ($totalMib === 0) {
            return $this->undetected();
        }

        $cards = count($names);

        return new Hardware(
            label: $cards === 1 ? $names[0] : "{$cards} x {$names[0]}",
            kind: MemoryKind::Discrete,
            totalBytes: $totalMib * self::BYTES_PER_MIB,
            reachableBytes: $totalMib * self::BYTES_PER_MIB,
            reachableNote: $cards === 1
                ? 'NVIDIA VRAM from nvidia-smi'
                : "NVIDIA VRAM summed over {$cards} cards from nvidia-smi (needs a split across cards, Chapter 24)",
        );
    }

    private function amd(): Hardware
    {
        $cards = json_decode($this->host->run(['rocm-smi', '--showmeminfo', 'vram', 'gtt', '--json']), true);

        if (! is_array($cards)) {
            return $this->undetected();
        }

        $vram = 0;

        $gtt = 0;

        foreach ($cards as $card) {
            $vram += (int) ($card['VRAM Total Memory (B)'] ?? 0);

            $gtt += (int) ($card['GTT Total Memory (B)'] ?? 0);
        }

        if ($vram + $gtt === 0) {
            return $this->undetected();
        }

        return new Hardware(
            label: 'AMD GPU (ROCm)',
            kind: MemoryKind::Unified,
            totalBytes: $this->linuxMemoryBytes(),
            reachableBytes: $vram + $gtt,
            reachableNote: sprintf('Linux AMD; carve-out %s + GTT %s from rocm-smi (assumes an APU; on a discrete card set AI_REACHABLE_MEMORY_GIB)', ByteSize::format($vram), ByteSize::format($gtt)),
        );
    }

    private function linuxSystemRam(): Hardware
    {
        $total = $this->linuxMemoryBytes();

        if ($total === 0) {
            return $this->undetected();
        }

        return new Hardware(
            label: 'CPU only',
            kind: MemoryKind::SystemRam,
            totalBytes: $total,
            reachableBytes: $total,
            reachableNote: 'Linux; no GPU found, the model would run from system RAM',
        );
    }

    private function linuxMemoryBytes(): int
    {
        preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $this->host->read('/proc/meminfo'), $matches);

        return (int) ($matches[1] ?? 0) * self::BYTES_PER_KIB;
    }

    private function undetected(): Hardware
    {
        return new Hardware(
            label: 'unknown',
            kind: MemoryKind::Unknown,
            totalBytes: 0,
            reachableBytes: 0,
            reachableNote: 'could not detect; set AI_REACHABLE_MEMORY_GIB',
        );
    }

    private function withOverrides(Hardware $hardware): Hardware
    {
        $reachableGib = config('ai.hardware.reachable_gib');

        if ($reachableGib !== null) {
            $hardware = $hardware->withReachable(
                ByteSize::fromGib($reachableGib),
                'set by AI_REACHABLE_MEMORY_GIB / --reachable-gib',
            );
        }

        $bandwidth = config('ai.hardware.bandwidth_gbps');

        if ($bandwidth !== null) {
            $hardware = $hardware->withBandwidth((float) $bandwidth);
        }

        return $hardware;
    }
}
