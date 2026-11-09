<?php

namespace App\Providers;

use App\Ai\Embeddings\Embedder;
use App\Ai\Embeddings\MarkdownChunker;
use App\Ai\Events\ModelCalled;
use App\Ai\Exceptions\UnsupportedTokenCounter;
use App\Ai\Http\AiHttpClient;
use App\Ai\Listeners\RecordModelCall;
use App\Ai\Tokens\RatioCounter;
use App\Ai\Tokens\TiktokenCounter;
use App\Ai\Tokens\TokenCounter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TokenCounter::class, fn () => $this->tokenCounter());

        $this->app->singleton(AiHttpClient::class, fn () => new AiHttpClient(config('ai')));

        $this->app->singleton(Embedder::class, fn () => new Embedder(
            client: $this->app->make(AiHttpClient::class),
            model: config('ai.embed_model'),
            dimensions: config('ai.embed_dimensions'),
        ));

        $this->app->singleton(MarkdownChunker::class, fn () => new MarkdownChunker(
            tokens: $this->app->make(TokenCounter::class),
            minTokens: config('ai.chunking.min_tokens'),
            maxTokens: config('ai.chunking.max_tokens'),
        ));
    }

    public function boot(): void
    {
        Event::listen(ModelCalled::class, RecordModelCall::class);

        RateLimiter::for('ai', fn (Request $request): Limit => Limit::perMinute((int) config('ai.rate_limit'))->by($request->user()?->id ?: $request->ip()));
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
