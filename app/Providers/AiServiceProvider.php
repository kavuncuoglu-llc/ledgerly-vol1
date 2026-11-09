<?php

namespace App\Providers;

use App\Ai\Events\ModelCalled;
use App\Ai\Exceptions\UnsupportedTokenCounter;
use App\Ai\Listeners\RecordModelCall;
use App\Ai\Tokens\RatioCounter;
use App\Ai\Tokens\TiktokenCounter;
use App\Ai\Tokens\TokenCounter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TokenCounter::class, fn () => $this->tokenCounter());
    }

    public function boot(): void
    {
        Event::listen(ModelCalled::class, RecordModelCall::class);
    }

    private function tokenCounter(): TokenCounter
    {
        $driver = (string) config('ai.token_counter');

        return match ($driver) {
            'ratio' => new RatioCounter,
            'tiktoken' => new TiktokenCounter,
            default => throw new UnsupportedTokenCounter($driver),
        };
    }
}
