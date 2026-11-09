<?php

namespace Tests\Unit\Ai\Hardware;

use App\Ai\Hardware\ByteSize;
use App\Ai\Hardware\HardwareProbe;
use App\Ai\Hardware\MemoryKind;
use Tests\Support\FakeHost;
use Tests\TestCase;

class HardwareProbeTest extends TestCase
{
    public function test_a_mac_gives_the_gpu_three_quarters_of_its_memory_by_default(): void
    {
        $hardware = (new HardwareProbe(FakeHost::macStudio(128)))->detect();

        $this->assertSame(MemoryKind::Unified, $hardware->kind);
        $this->assertSame(128.0, ByteSize::gib($hardware->totalBytes));
        $this->assertSame(96.0, ByteSize::gib($hardware->reachableBytes));
        $this->assertStringContainsString('iogpu.wired_limit_mb unset', $hardware->reachableNote);
    }

    public function test_the_default_share_is_configurable(): void
    {
        config()->set('ai.hardware.macos_gpu_share', 0.78);

        $hardware = (new HardwareProbe(FakeHost::macStudio(32)))->detect();

        $this->assertEqualsWithDelta(24.96, ByteSize::gib($hardware->reachableBytes), 0.01);
    }

    public function test_a_raised_wired_limit_replaces_the_default(): void
    {
        $hardware = (new HardwareProbe(FakeHost::macStudio(128, wiredLimitMib: 110_000)))->detect();

        $this->assertSame(110_000 * 1_048_576, $hardware->reachableBytes);
        $this->assertStringContainsString('iogpu.wired_limit_mb = 110000', $hardware->reachableNote);
    }

    public function test_known_apple_chips_get_a_bandwidth_and_unknown_ones_do_not(): void
    {
        $known = (new HardwareProbe(FakeHost::macStudio(32, chip: 'Apple M1 Pro')))->detect();
        $unknown = (new HardwareProbe(FakeHost::macStudio(32, chip: 'Apple M9 Plus')))->detect();

        $this->assertSame(200.0, $known->bandwidthGbps);
        $this->assertSame(0.0, $unknown->bandwidthGbps);
    }

    public function test_the_bandwidth_can_be_set_by_hand(): void
    {
        config()->set('ai.hardware.bandwidth_gbps', 420);

        $this->assertSame(420.0, (new HardwareProbe(FakeHost::macStudio(128, chip: 'Apple M9 Plus')))->detect()->bandwidthGbps);
    }

    public function test_one_nvidia_card_is_discrete_memory(): void
    {
        $host = new FakeHost('Linux', [
            'nvidia-smi --query-gpu=name,memory.total --format=csv,noheader,nounits' => 'NVIDIA GeForce RTX 4090, 24564',
        ]);

        $hardware = (new HardwareProbe($host))->detect();

        $this->assertSame(MemoryKind::Discrete, $hardware->kind);
        $this->assertSame('NVIDIA GeForce RTX 4090', $hardware->label);
        $this->assertSame(24564 * 1_048_576, $hardware->reachableBytes);
    }

    public function test_several_nvidia_cards_are_summed_with_a_warning_in_the_note(): void
    {
        $host = new FakeHost('Linux', [
            'nvidia-smi --query-gpu=name,memory.total --format=csv,noheader,nounits' => "RTX 4090, 24564\nRTX 4090, 24564",
        ]);

        $hardware = (new HardwareProbe($host))->detect();

        $this->assertSame('2 x RTX 4090', $hardware->label);
        $this->assertSame(2 * 24564 * 1_048_576, $hardware->reachableBytes);
        $this->assertStringContainsString('summed over 2 cards', $hardware->reachableNote);
    }

    public function test_an_amd_apu_reaches_the_carve_out_plus_the_gtt(): void
    {
        $host = new FakeHost('Linux', [
            'rocm-smi --showmeminfo vram gtt --json' => json_encode([
                'card0' => [
                    'VRAM Total Memory (B)' => (string) (64 * 1_073_741_824),
                    'GTT Total Memory (B)' => (string) (48 * 1_073_741_824),
                ],
            ]),
        ], [
            '/proc/meminfo' => "MemTotal:       131072000 kB\nMemFree: 1 kB",
        ]);

        $hardware = (new HardwareProbe($host))->detect();

        $this->assertSame(MemoryKind::Unified, $hardware->kind);
        $this->assertSame(112.0, ByteSize::gib($hardware->reachableBytes));
        $this->assertStringContainsString('carve-out 64.0 GiB + GTT 48.0 GiB', $hardware->reachableNote);
    }

    public function test_a_linux_box_with_no_gpu_runs_from_system_ram(): void
    {
        $host = new FakeHost('Linux', [], ['/proc/meminfo' => 'MemTotal:       16777216 kB']);

        $hardware = (new HardwareProbe($host))->detect();

        $this->assertSame(MemoryKind::SystemRam, $hardware->kind);
        $this->assertSame(16.0, ByteSize::gib($hardware->reachableBytes));
    }

    public function test_an_unknown_platform_is_reported_as_undetected(): void
    {
        $hardware = (new HardwareProbe(new FakeHost('Windows')))->detect();

        $this->assertFalse($hardware->isDetected());
        $this->assertStringContainsString('AI_REACHABLE_MEMORY_GIB', $hardware->reachableNote);
    }

    public function test_the_reachable_override_wins_even_on_an_undetected_platform(): void
    {
        config()->set('ai.hardware.reachable_gib', 96.0);

        $hardware = (new HardwareProbe(new FakeHost('Windows')))->detect();

        $this->assertTrue($hardware->isDetected());
        $this->assertSame(96.0, ByteSize::gib($hardware->reachableBytes));
    }
}
