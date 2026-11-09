<?php

namespace Tests\Feature\Console;

use App\Ai\Profile\MemoryProbeFactory;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FixedMemoryProbe;
use Tests\Support\FixedMemoryProbeFactory;
use Tests\TestCase;

class AiProfileSampleCommandTest extends TestCase
{
    public function test_it_prints_one_json_sample_per_line_and_stops_after_count(): void
    {
        $this->app->instance(MemoryProbeFactory::class, new FixedMemoryProbeFactory(new FixedMemoryProbe(modelLoaded: true)));

        $exitCode = Artisan::call('ai:profile-sample', ['--provider' => 'local', '--interval' => 0, '--count' => 3]);

        $lines = array_filter(explode("\n", Artisan::output()));

        $this->assertSame(0, $exitCode);
        $this->assertCount(3, $lines);
        $this->assertSame(
            ['at' => 1234.5, 'resident' => 1_073_741_824, 'gpu' => 2_147_483_648, 'spilled' => 0, 'loaded' => true],
            json_decode(reset($lines), true),
        );
    }

    public function test_it_is_hidden_from_the_command_list(): void
    {
        $this->assertTrue(Artisan::all()['ai:profile-sample']->isHidden());
    }
}
