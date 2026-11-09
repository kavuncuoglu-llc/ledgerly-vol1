<?php

namespace App\Ai;

use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\EmbeddingModel;
use App\Ai\Contracts\Reranker;
use App\Ai\Decorators\ProfileMergingModel;
use App\Ai\Decorators\RetryingEmbeddingModel;
use App\Ai\Decorators\RetryingModel;
use App\Ai\Decorators\UsageLoggingModel;
use App\Ai\Drivers\AnthropicDriver;
use App\Ai\Drivers\FakeDriver;
use App\Ai\Drivers\FakeEmbeddings;
use App\Ai\Drivers\GeminiDriver;
use App\Ai\Drivers\OpenAiCompatibleDriver;
use App\Ai\Drivers\OpenAiCompatibleEmbeddings;
use App\Ai\Drivers\Support\DebugLog;
use App\Ai\Exceptions\UnknownProvider;
use App\Ai\Retrieval\LexicalReranker;
use App\Ai\Retrieval\SidecarReranker;
use App\Ai\Sidecar\SidecarClient;
use App\Ai\Tokens\TokenCounter;
use Closure;
use Illuminate\Contracts\Container\Container;

class AiManager
{
    private const DEFAULT_BASE_URLS = [
        'anthropic' => 'https://api.anthropic.com',
        'gemini' => 'https://generativelanguage.googleapis.com',
    ];

    private array $chatModels = [];

    private array $embeddingModels = [];

    private array $rerankers = [];

    private array $extensions = [];

    public function __construct(protected readonly Container $app) {}

    public function chat(?string $name = null): ChatModel
    {
        $name ??= (string) config('ai.default');

        return $this->chatModels[$name] ??= $this->decorate($this->makeChatDriver($name));
    }

    public function for(string $feature): ChatModel
    {
        return $this->chat($this->providerNameFor($feature));
    }

    public function providerNameFor(string $feature): string
    {
        return (string) (config('ai.features', [])[$feature]['provider'] ?? config('ai.default'));
    }

    public function hasEscalation(string $feature): bool
    {
        $target = $this->escalationTarget($feature);

        if ($target === null) {
            return false;
        }

        return $this->isConfigured($target);
    }

    public function escalation(string $feature): ChatModel
    {
        return $this->chat($this->escalationTarget($feature) ?? $this->providerNameFor($feature));
    }

    public function embed(?string $name = null): EmbeddingModel
    {
        $name ??= (string) config('ai.embedding.provider');

        return $this->embeddingModels[$name] ??= $this->makeEmbeddingModel($name);
    }

    public function embedUsing(string $model, ?int $dimensions = null, ?string $name = null): EmbeddingModel
    {
        $name ??= (string) config('ai.embedding.provider');

        $config = $this->providerConfig($name);

        return new RetryingEmbeddingModel(new OpenAiCompatibleEmbeddings([
            ...$config,
            'model' => $model,
            'dimensions' => $dimensions ?? $config['dimensions'],
        ]));
    }

    public function reranker(?string $name = null): Reranker
    {
        $name ??= (string) config('ai.reranking.provider');

        return $this->rerankers[$name] ??= $this->makeReranker($name);
    }

    public function extend(string $driver, Closure $factory): void
    {
        $this->extensions[$driver] = $factory;

        $this->forget();
    }

    public function forget(): void
    {
        $this->chatModels = [];
        $this->embeddingModels = [];
        $this->rerankers = [];
    }

    public function providerNames(): array
    {
        return array_keys((array) config('ai.providers', []));
    }

    public function providerConfig(string $name): array
    {
        $config = config("ai.providers.{$name}");

        if (! is_array($config)) {
            throw UnknownProvider::named($name);
        }

        $driver = (string) ($config['driver'] ?? '');

        return [
            'timeout' => config('ai.timeout'),
            'connect_timeout' => config('ai.connect_timeout'),
            'base_url' => self::DEFAULT_BASE_URLS[$driver] ?? null,
            ...array_filter($config, fn (mixed $value): bool => $value !== null),
            'name' => $name,
        ];
    }

    public function isConfigured(string $name): bool
    {
        $config = $this->providerConfig($name);

        if (($config['driver'] ?? '') === 'fake') {
            return true;
        }

        return filled($config['api_key'] ?? null) && filled($config['model'] ?? null);
    }

    protected function escalationTarget(string $feature): ?string
    {
        $target = config('ai.features', [])[$feature]['escalate_to'] ?? null;

        if (blank($target)) {
            return null;
        }

        return (string) $target;
    }

    protected function decorate(ChatModel $driver): ChatModel
    {
        return new UsageLoggingModel(new RetryingModel(new ProfileMergingModel($driver)));
    }

    protected function makeChatDriver(string $name): ChatModel
    {
        $config = $this->providerConfig($name);

        $driver = (string) ($config['driver'] ?? '');

        if (isset($this->extensions[$driver])) {
            return ($this->extensions[$driver])($config, $this->app);
        }

        return match ($driver) {
            'openai-compatible' => new OpenAiCompatibleDriver($config, $this->app->make(TokenCounter::class), $this->app->make(DebugLog::class)),
            'anthropic' => new AnthropicDriver($config, $this->app->make(DebugLog::class)),
            'gemini' => new GeminiDriver($config, $this->app->make(DebugLog::class)),
            'fake' => new FakeDriver($config),
            'sidecar', 'lexical' => throw UnknownProvider::notChat($name, $driver),
            default => throw UnknownProvider::driver($name, $driver),
        };
    }

    protected function makeEmbeddingModel(string $name): EmbeddingModel
    {
        $config = $this->providerConfig($name);

        $driver = (string) ($config['driver'] ?? '');

        return match ($driver) {
            'openai-compatible' => new RetryingEmbeddingModel(new OpenAiCompatibleEmbeddings($config)),
            'fake' => new FakeEmbeddings($config),
            default => throw UnknownProvider::notEmbedding($name, $driver),
        };
    }

    protected function makeReranker(string $name): Reranker
    {
        $driver = (string) ($this->providerConfig($name)['driver'] ?? '');

        return match ($driver) {
            'sidecar' => new SidecarReranker($this->app->make(SidecarClient::class)),
            'lexical' => new LexicalReranker,
            default => throw UnknownProvider::notReranker($name, $driver),
        };
    }
}
