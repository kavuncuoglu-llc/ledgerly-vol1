<?php

namespace Tests\Unit\Ai\Profile;

use App\Ai\Profile\MemorySample;
use App\Ai\Profile\ProcessBackgroundSampler;
use Tests\TestCase;

class ProcessBackgroundSamplerTest extends TestCase
{
    public function test_stopping_a_sampler_that_never_started_returns_nothing(): void
    {
        $this->assertSame([], (new ProcessBackgroundSampler)->stop());
    }

    public function test_a_memory_sample_survives_the_json_round_trip_between_the_processes(): void
    {
        $sample = new MemorySample(12.5, 100, 200, 3, true);

        $this->assertEquals($sample, MemorySample::fromArray($sample->toArray()));
    }
}
