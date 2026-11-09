<?php

namespace Tests\Feature\Ai;

use Tests\TestCase;

class ApiKeyTest extends TestCase
{
    public function test_the_testing_environment_holds_no_real_api_key(): void
    {
        $key = (string) config('ai.api_key');

        $this->assertFalse(str_starts_with($key, 'sk-'));
        $this->assertFalse(str_starts_with($key, 'AIza'));
    }

    public function test_the_committed_env_example_holds_no_real_api_key(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('AI_API_KEY=ollama', $example);
    }
}
