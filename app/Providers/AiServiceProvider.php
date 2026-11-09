<?php

namespace App\Providers;

use App\Ai\AiManager;
use App\Ai\Cache\HelpVersion;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\EmbeddingModel;
use App\Ai\Contracts\Reranker;
use App\Ai\Conversations\ConversationStore;
use App\Ai\Conversations\EloquentConversationStore;
use App\Ai\Drivers\Support\DebugLog;
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
use App\Ai\Listeners\RecordModelCall;
use App\Ai\Listeners\RecordToolCall;
use App\Ai\Listeners\TriageTicket;
use App\Ai\Parsing\DocumentParser;
use App\Ai\Parsing\SidecarPdfParser;
use App\Ai\Retrieval\ContextualizeChunks;
use App\Ai\Retrieval\Retriever;
use App\Ai\Retrieval\RetrieverFactory;
use App\Ai\Sidecar\SidecarClient;
use App\Ai\Support\Router;
use App\Ai\Support\VoteClassifier;
use App\Ai\Tokens\RatioCounter;
use App\Ai\Tokens\TiktokenCounter;
use App\Ai\Tokens\TokenCounter;
use App\Ai\Tools\AccountStatus\AccountStatus;
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

        $this->app->singleton(AiManager::class, fn () => new AiManager($this->app));

        $this->app->singleton(DebugLog::class);

        $this->app->bind(ChatModel::class, fn () => $this->app->make(AiManager::class)->chat());

        $this->app->bind(EmbeddingModel::class, fn () => $this->app->make(AiManager::class)->embed());

        $this->app->bind(Reranker::class, fn () => $this->app->make(AiManager::class)->reranker());

        $this->app->singleton(HelpVersion::class, fn () => new HelpVersion(resource_path('help')));

        $this->app->bind(Embedder::class, fn () => new Embedder($this->app->make(AiManager::class)->embed()));

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
            ->add(new AccountStatus)
            ->addSource($this->app->make(McpToolSource::class)));

        $this->app->singleton(Router::class, fn () => new Router(
            classifier: $this->app->make(VoteClassifier::class),
            samples: (int) config('ai.routing.samples'),
            agreementThreshold: (float) config('ai.routing.agreement_threshold'),
        ));

        $this->app->singleton(ParentChildChunker::class, fn () => new ParentChildChunker(
            tokens: $this->app->make(TokenCounter::class),
            maxChildTokens: (int) config('ai.chunking.child_max_tokens'),
        ));

        $this->app->singleton(ContextualizeChunks::class, fn () => new ContextualizeChunks(
            ai: $this->app->make(AiManager::class),
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

        Event::listen(TicketReceived::class, TriageTicket::class);

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
