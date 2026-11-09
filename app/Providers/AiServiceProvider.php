<?php

namespace App\Providers;

use App\Ai\Cache\HelpVersion;
use App\Ai\Context\ProviderCapabilities;
use App\Ai\Conversations\ConversationStore;
use App\Ai\Conversations\EloquentConversationStore;
use App\Ai\Embeddings\BoilerplateStripper;
use App\Ai\Embeddings\Embedder;
use App\Ai\Embeddings\MarkdownChunker;
use App\Ai\Embeddings\ParentChildChunker;
use App\Ai\Encoding\DataEncoder;
use App\Ai\Encoding\Formats\JsonFormat;
use App\Ai\Encoding\Formats\MarkdownFormat;
use App\Ai\Encoding\Formats\ToonFormat;
use App\Ai\Encoding\Formats\TsvFormat;
use App\Ai\Encoding\Formats\YamlFormat;
use App\Ai\Events\ModelCalled;
use App\Ai\Events\ToolCalled;
use App\Ai\Exceptions\UnsupportedTokenCounter;
use App\Ai\Http\AiHttpClient;
use App\Ai\Listeners\ClassifyReceivedTicket;
use App\Ai\Listeners\RecordModelCall;
use App\Ai\Listeners\RecordToolCall;
use App\Ai\Parsing\DocumentParser;
use App\Ai\Parsing\SidecarPdfParser;
use App\Ai\Retrieval\ContextualizeChunks;
use App\Ai\Retrieval\Retriever;
use App\Ai\Retrieval\RetrieverFactory;
use App\Ai\Sidecar\SidecarClient;
use App\Ai\Tokens\RatioCounter;
use App\Ai\Tokens\TiktokenCounter;
use App\Ai\Tokens\TokenCounter;
use App\Ai\Tools\ListInvoices\ListInvoices;
use App\Ai\Tools\LookupInvoice\LookupInvoice;
use App\Ai\Tools\Mcp\McpClientFactory;
use App\Ai\Tools\Mcp\McpToolSource;
use App\Ai\Tools\OpenTicket\OpenTicket;
use App\Ai\Tools\ToolRegistry;
use App\Events\TicketReceived;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TokenCounter::class, fn () => $this->tokenCounter());

        $this->app->singleton(CurrentTenant::class);

        $this->app->singleton(ConversationStore::class, EloquentConversationStore::class);

        $this->app->singleton(AiHttpClient::class, fn () => new AiHttpClient(config('ai')));

        $this->app->singleton(ProviderCapabilities::class, fn () => new ProviderCapabilities(
            provider: (string) config('ai.provider'),
            cacheBreakpointsOverride: config('ai.prompt_cache.breakpoints') === null
                ? null
                : filter_var(config('ai.prompt_cache.breakpoints'), FILTER_VALIDATE_BOOLEAN),
        ));

        $this->app->singleton(HelpVersion::class, fn () => new HelpVersion(resource_path('help')));

        $this->app->singleton(Embedder::class, fn () => new Embedder(
            client: $this->app->make(AiHttpClient::class),
            model: config('ai.embed_model'),
            dimensions: config('ai.embed_dimensions'),
        ));

        $this->app->singleton(BoilerplateStripper::class, fn () => new BoilerplateStripper(config('ai.ingestion.boilerplate_patterns')));

        $this->app->singleton(SidecarClient::class, fn () => new SidecarClient(
            baseUrl: (string) config('ai.sidecar.url'),
            timeout: (int) config('ai.sidecar.timeout'),
            parseTimeout: (int) config('ai.sidecar.parse_timeout'),
            maxUploadMegabytes: (int) config('ai.sidecar.max_upload_mb'),
        ));

        $this->app->bind(DocumentParser::class, SidecarPdfParser::class);

        $this->app->bind(Retriever::class, fn () => $this->app->make(RetrieverFactory::class)->make());

        $this->app->singleton(DataEncoder::class, fn () => new DataEncoder([
            'toon' => new ToonFormat,
            'tsv' => new TsvFormat,
            'markdown' => new MarkdownFormat,
            'json' => new JsonFormat,
            'json-pretty' => new JsonFormat(pretty: true),
            'yaml' => new YamlFormat,
        ]));

        $this->app->singleton(McpClientFactory::class);

        $this->app->singleton(McpToolSource::class, fn () => new McpToolSource(
            servers: config('ai.mcp_servers'),
            clientFor: fn (string $server, array $config) => $this->app->make(McpClientFactory::class)->make($server, $config),
        ));

        $this->app->singleton(ToolRegistry::class, fn () => (new ToolRegistry)
            ->add(new LookupInvoice)
            ->add(new ListInvoices($this->app->make(DataEncoder::class)))
            ->add(new OpenTicket)
            ->addSource($this->app->make(McpToolSource::class)));

        $this->app->singleton(ParentChildChunker::class, fn () => new ParentChildChunker(
            tokens: $this->app->make(TokenCounter::class),
            maxChildTokens: (int) config('ai.chunking.child_max_tokens'),
        ));

        $this->app->singleton(ContextualizeChunks::class, fn () => new ContextualizeChunks(
            client: $this->app->make(AiHttpClient::class),
            capabilities: $this->app->make(ProviderCapabilities::class),
            model: config('ai.retrieval.context_model'),
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

        Event::listen(ToolCalled::class, RecordToolCall::class);

        Gate::define('use-tool', fn (User $user, string $tool, ?string $tokenName = null): bool => $this->mayUseTool($user, $tool, $tokenName));

        Event::listen(TicketReceived::class, ClassifyReceivedTicket::class);

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

    private function mayUseTool(User $user, string $tool, ?string $tokenName): bool
    {
        if ($user->customer_id === null) {
            return false;
        }

        if ($tokenName === null) {
            return true;
        }

        $allowList = config('ai.tool_allow_lists', [])[$tokenName] ?? null;

        if ($allowList === null) {
            return true;
        }

        return in_array($tool, $allowList, true);
    }
}
