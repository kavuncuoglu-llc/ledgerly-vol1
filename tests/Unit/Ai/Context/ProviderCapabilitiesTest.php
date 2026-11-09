<?php

namespace Tests\Unit\Ai\Context;

use App\Ai\Context\ProviderCapabilities;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProviderCapabilitiesTest extends TestCase
{
    #[DataProvider('providers')]
    public function test_supports_cache_breakpoints_follows_the_provider_unless_overridden(string $provider, ?bool $override, bool $expected): void
    {
        $capabilities = new ProviderCapabilities($provider, $override);

        $this->assertSame($expected, $capabilities->supportsCacheBreakpoints());
    }

    public static function providers(): array
    {
        return [
            'ollama by default' => ['ollama', null, false],
            'openai by default' => ['openai', null, false],
            'anthropic by default' => ['anthropic', null, true],
            'forced on' => ['ollama', true, true],
            'forced off' => ['anthropic', false, false],
        ];
    }
}
