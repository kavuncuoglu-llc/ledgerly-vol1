<?php

namespace App\Ai\Context;

final class ProviderCapabilities
{
    public function __construct(
        private readonly string $provider,
        private readonly ?bool $cacheBreakpointsOverride = null,
    ) {}

    public function supportsCacheBreakpoints(): bool
    {
        if ($this->cacheBreakpointsOverride !== null) {
            return $this->cacheBreakpointsOverride;
        }

        return $this->provider === 'anthropic';
    }
}
