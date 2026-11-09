<?php

namespace Tests\Unit\Ai\Compare;

use App\Ai\Compare\Cases\Sampler;
use PHPUnit\Framework\TestCase;

class SamplerTest extends TestCase
{
    public function test_it_spreads_the_picks_evenly_through_the_list(): void
    {
        $this->assertSame([1, 4, 7, 10], array_values(Sampler::spread(range(1, 12), 4)));
        $this->assertSame([0, 3, 6, 9], array_keys(Sampler::spread(range(1, 12), 4)));
    }

    public function test_a_zero_or_oversized_limit_returns_everything(): void
    {
        $this->assertSame([1, 2, 3], Sampler::spread([1, 2, 3], 0));
        $this->assertSame([1, 2, 3], Sampler::spread([1, 2, 3], 10));
    }
}
