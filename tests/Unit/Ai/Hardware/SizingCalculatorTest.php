<?php

namespace Tests\Unit\Ai\Hardware;

use App\Ai\Hardware\ByteSize;
use App\Ai\Hardware\Hardware;
use App\Ai\Hardware\MemoryKind;
use App\Ai\Hardware\ModelSpecs;
use App\Ai\Hardware\SizingCalculator;
use InvalidArgumentException;
use Tests\TestCase;

class SizingCalculatorTest extends TestCase
{
    public function test_the_kv_cache_per_token_matches_the_books_figures(): void
    {
        $eight = (new ModelSpecs)->resolve('llama3.1:8b');
        $seventy = (new ModelSpecs)->resolve('llama3.1:70b');

        $this->assertSame(131_072.0, $eight->kvBytesPerToken(2.0));
        $this->assertSame(327_680.0, $seventy->kvBytesPerToken(2.0));
    }

    public function test_the_sum_is_weights_plus_cache_plus_twelve_percent_headroom(): void
    {
        $spec = (new ModelSpecs)->resolve('llama3.1:8b');

        $result = $this->calculator()->estimate($spec, $this->machine(32), context: 8192, slots: 4);

        $this->assertSame(4 * 8192 * 131_072, $result->cacheBytes);
        $this->assertEqualsWithDelta(($result->weightsBytes + $result->cacheBytes) * 0.12, $result->headroomBytes, 1);
        $this->assertSame($result->weightsBytes + $result->cacheBytes + $result->headroomBytes, $result->totalBytes);
    }

    public function test_a_q8_cache_is_a_little_over_half_of_f16(): void
    {
        $spec = (new ModelSpecs)->resolve('llama3.1:8b');

        $f16 = $this->calculator()->estimate($spec, $this->machine(32), context: 8192, slots: 2, cacheType: 'f16');
        $q8 = $this->calculator()->estimate($spec, $this->machine(32), context: 8192, slots: 2, cacheType: 'q8_0');

        $this->assertSame($f16->cacheBytesAtQ8, $q8->cacheBytes);
        $this->assertEqualsWithDelta(0.53, $q8->cacheBytes / $f16->cacheBytes, 0.01);
    }

    public function test_the_headroom_grows_with_a_reserve_for_other_processes(): void
    {
        $spec = (new ModelSpecs)->resolve('llama3.1:8b');

        $plain = $this->calculator()->estimate($spec, $this->machine(32), context: 8192, slots: 1);
        $reserved = $this->calculator()->estimate($spec, $this->machine(32), context: 8192, slots: 1, reserveBytes: ByteSize::fromGib(4));

        $this->assertSame(ByteSize::fromGib(4), $reserved->totalBytes - $plain->totalBytes);
    }

    public function test_a_model_that_fits_reports_its_spare_memory_and_no_alternatives(): void
    {
        $spec = (new ModelSpecs)->resolve('llama3.1:70b-instruct-q4_K_M');

        $result = $this->calculator()->estimate($spec, $this->machine(128), context: 8192, slots: 2);

        $this->assertTrue($result->fits());
        $this->assertGreaterThan(0, $result->spareBytes());
        $this->assertSame([], $result->alternatives);
    }

    public function test_a_model_that_does_not_fit_offers_each_lever_and_says_which_would_work(): void
    {
        $spec = (new ModelSpecs)->resolve('llama3.1:8b');

        $result = $this->calculator()->estimate($spec, $this->machine(5), context: 8192, slots: 2);

        $this->assertFalse($result->fits());
        $this->assertLessThan(0, $result->spareBytes());

        $changes = array_column($result->alternatives, 'change');

        $this->assertContains('quantize the KV cache to q8_0', $changes);
        $this->assertContains('drop to q3_k_m', $changes);
        $this->assertContains('halve the context to 4096', $changes);
        $this->assertContains('use one slot', $changes);
    }

    public function test_an_alternative_that_is_enough_is_marked_as_fitting(): void
    {
        $spec = (new ModelSpecs)->resolve('llama3.1:8b');

        $result = $this->calculator()->estimate($spec, $this->machine(8), context: 32768, slots: 1);

        $byChange = array_column($result->alternatives, 'fits', 'change');

        $this->assertFalse($result->fits());
        $this->assertTrue($byChange['halve the context to 16384']);
    }

    public function test_the_binding_constraint_is_the_largest_term(): void
    {
        $spec = (new ModelSpecs)->resolve('llama3.1:8b');

        $weights = $this->calculator()->estimate($spec, $this->machine(32), context: 2048, slots: 1);
        $cache = $this->calculator()->estimate($spec, $this->machine(32), context: 131_072, slots: 4);

        $this->assertSame('weights', $weights->bindingConstraint());
        $this->assertSame('cache', $cache->bindingConstraint());
    }

    public function test_the_lowest_quantization_has_no_lower_alternative(): void
    {
        $spec = (new ModelSpecs)->resolve('llama3.1:70b-instruct-q3_K_M');

        $result = $this->calculator()->estimate($spec, $this->machine(8), context: 8192, slots: 1);

        $this->assertNotContains('drop to', array_map(fn ($alternative): string => substr($alternative->change, 0, 7), $result->alternatives));
    }

    public function test_an_unknown_cache_type_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator()->estimate((new ModelSpecs)->resolve('llama3.1:8b'), $this->machine(32), context: 8192, slots: 1, cacheType: 'q2');
    }

    public function test_an_undetected_machine_never_fits(): void
    {
        $result = $this->calculator()->estimate((new ModelSpecs)->resolve('llama3.1:8b'), $this->machine(0), context: 2048, slots: 1);

        $this->assertFalse($result->fits());
    }

    private function calculator(): SizingCalculator
    {
        return new SizingCalculator(new ModelSpecs);
    }

    private function machine(float $reachableGib): Hardware
    {
        return new Hardware(
            label: 'test machine',
            kind: MemoryKind::Unified,
            totalBytes: ByteSize::fromGib($reachableGib),
            reachableBytes: ByteSize::fromGib($reachableGib),
            reachableNote: 'test',
        );
    }
}
