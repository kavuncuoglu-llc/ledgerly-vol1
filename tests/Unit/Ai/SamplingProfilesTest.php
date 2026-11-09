<?php

namespace Tests\Unit\Ai;

use Tests\TestCase;

class SamplingProfilesTest extends TestCase
{
    public function test_profiles_match_the_chapter_five_starting_values(): void
    {
        $this->assertSame(
            [
                'deterministic' => ['temperature' => 0, 'top_p' => 1.0, 'seed' => 42],
                'grounded' => ['temperature' => 0.2, 'top_p' => 0.9],
                'assistant' => ['temperature' => 0.7, 'top_p' => 0.9],
                'creative' => ['temperature' => 1.0, 'top_p' => 0.95],
            ],
            config('ai.profiles'),
        );
    }
}
