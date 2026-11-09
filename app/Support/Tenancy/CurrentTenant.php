<?php

namespace App\Support\Tenancy;

use Closure;
use Illuminate\Support\Facades\Auth;

final class CurrentTenant
{
    private array $overrides = [];

    public function id(): ?int
    {
        if ($this->overrides !== []) {
            return end($this->overrides);
        }

        return Auth::user()?->customer_id;
    }

    public function runAs(?int $tenantId, Closure $callback): mixed
    {
        $this->overrides[] = $tenantId;

        try {
            return $callback();
        } finally {
            array_pop($this->overrides);
        }
    }
}
