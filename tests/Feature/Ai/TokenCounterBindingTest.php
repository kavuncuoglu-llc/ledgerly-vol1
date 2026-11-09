<?php

namespace Tests\Feature\Ai;

use App\Ai\Exceptions\UnsupportedTokenCounter;
use App\Ai\Tokens\RatioCounter;
use App\Ai\Tokens\TiktokenCounter;
use App\Ai\Tokens\TokenCounter;
use Tests\TestCase;

class TokenCounterBindingTest extends TestCase
{
    public function test_container_resolves_the_ratio_counter_by_default(): void
    {
        $counter = app(TokenCounter::class);

        $this->assertInstanceOf(RatioCounter::class, $counter);
    }

    public function test_container_resolves_the_tiktoken_counter_when_configured(): void
    {
        config()->set('ai.token_counter', 'tiktoken');

        $counter = app(TokenCounter::class);

        $this->assertInstanceOf(TiktokenCounter::class, $counter);
    }

    public function test_container_rejects_a_counter_that_is_not_built_yet(): void
    {
        config()->set('ai.token_counter', 'server');

        $this->expectException(UnsupportedTokenCounter::class);

        app(TokenCounter::class);
    }
}
