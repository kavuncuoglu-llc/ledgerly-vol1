<?php

namespace App\Ai\Support;

use App\Ai\Cache\CacheLookup;
use App\Ai\Cache\SupportCacheGate;
use App\Ai\Context\AssembledPrompt;
use App\Ai\Conversations\ConversationStore;
use App\Ai\Conversations\HistoryBudget;
use App\Ai\Conversations\SummarizeConversation;
use App\Ai\Events\ModelCalled;
use App\Ai\Http\CallDetails;
use App\Ai\Http\ChatResponse;
use App\Ai\Http\Usage;
use App\Ai\Memory\MemoryStore;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Retrieval\CitationChecker;
use App\Ai\Retrieval\CitationReport;
use App\Ai\Retrieval\RetrievalScope;
use App\Ai\Retrieval\Retriever;
use App\Ai\Streaming\StreamTaps;
use App\Ai\Structured\RepairPrompt;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRunner;
use App\Ai\Tools\ToolRunResult;
use App\Models\CachedAnswer;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\User;
use Closure;

final class SupportAssistant
{
    public const CACHE_PROVIDER = 'semantic-cache';

    public const CACHE_MODEL = 'cached_answers';

    public function __construct(
        private readonly ToolRunner $runner,
        private readonly ConversationStore $store,
        private readonly HistoryBudget $budget,
        private readonly MemoryStore $memories,
        private readonly SupportCacheGate $cache,
        private readonly Retriever $retriever,
        private readonly CitationChecker $citations,
        private readonly Router $router,
    ) {}

    public function answer(User $user, string $question, ?Conversation $conversation = null): ToolRunResult
    {
        return $this->respond($user, $question, $conversation, null);
    }

    public function answerStreaming(User $user, string $question, Conversation $conversation, StreamTaps $taps): ToolRunResult
    {
        return $this->respond($user, $question, $conversation, $taps);
    }

    private function respond(User $user, string $question, ?Conversation $conversation, ?StreamTaps $taps): ToolRunResult
    {
        $started = hrtime(true);

        $conversation ??= $this->store->start($user, SupportPrompt::VERSION);

        $lookup = $this->cache->lookup($user, $question, $conversation);

        if ($lookup->isHit()) {
            return $this->answerFromCache($conversation, $question, $lookup, $started);
        }

        $route = $this->routeFor($question);

        $allowance = $this->budget->historyAllowance(SupportPrompt::system($user));

        $chunks = $this->retriever->retrieve($question, $this->scopeFor($user, $lookup, $route), k: config('ai.retrieval.k'));

        $assembled = $this->assemble($user, $question, $conversation, $allowance, $chunks);

        $runner = $this->runnerFor($route);

        $run = fn (array $messages, ?StreamTaps $streamTaps = null): ToolRunResult => $runner->run(
            messages: $messages,
            ctx: new ToolContext(user: $user, conversationId: $conversation->id),
            options: [
                'profile' => 'assistant',
                'max_tokens' => config('ai.budget.answer'),
            ],
            feature: 'support.reply',
            promptVersion: SupportPrompt::VERSION,
            details: new CallDetails(
                contextBreakdown: $assembled->breakdown,
                semanticCacheHit: $lookup->semanticCacheHit(),
            ),
            taps: $streamTaps,
        );

        $result = $this->enforceCitations($run($assembled->messages, $taps), $assembled, $run);

        $this->cache->store($user, $question, $lookup, $result, grounded: $assembled->labelMap !== [] && $result->citationsOk);

        $this->remember($conversation, $question, $result);

        $this->recordSources($conversation, $result, $assembled);

        $this->memories->markUsed($user);

        $this->summarizeInBackground($conversation, $allowance);

        return $result->forConversation($conversation->id);
    }

    private function routeFor(string $question): Route
    {
        if (! config('ai.routing.enabled')) {
            return Route::unrouted();
        }

        return $this->router->route($question);
    }

    private function runnerFor(Route $route): ToolRunner
    {
        if (! $route->isAutomated()) {
            return $this->runner;
        }

        return $this->runner->restrictedTo($route->tools);
    }

    private function scopeFor(User $user, CacheLookup $lookup, Route $route): RetrievalScope
    {
        return new RetrievalScope(
            tenantId: $user->customer_id,
            plan: (string) $user->customer?->plan,
            area: $this->areaFor($lookup, $route),
        );
    }

    private function areaFor(CacheLookup $lookup, Route $route): string
    {
        if ($route->isAutomated()) {
            return $route->area;
        }

        if (! $lookup->attempted) {
            return '';
        }

        return $lookup->category->areaIfConfident();
    }

    private function enforceCitations(ToolRunResult $result, AssembledPrompt $assembled, Closure $run): ToolRunResult
    {
        if ($result->hasPending()) {
            return $result;
        }

        if ($result->hitCap) {
            return $result;
        }

        $report = $this->audit($result, $assembled);

        if (! $report->hasErrors()) {
            return $this->citedResult($result, $report);
        }

        $repaired = $run([
            ...$assembled->messages,
            ...$this->withoutCallIds($result->newMessages()),
            RepairPrompt::citations($report),
        ]);

        $secondReport = $this->audit($repaired, $assembled);

        if (! $secondReport->hasErrors()) {
            return $this->citedResult($repaired, $secondReport);
        }

        return $repaired
            ->withContent(SupportPrompt::escapeHatch())
            ->withCitations([], false);
    }

    private function audit(ToolRunResult $result, AssembledPrompt $assembled): CitationReport
    {
        $report = $this->citations->check(
            answer: $result->answer(),
            labelToChunk: $assembled->labelMap,
            requireCitations: $assembled->labelMap !== [] && ! $result->usedTools(),
        );

        $this->recordCitationErrors($result, $report);

        return $report;
    }

    private function citedResult(ToolRunResult $result, CitationReport $report): ToolRunResult
    {
        $sources = [];

        foreach ($report->cited as $label => $chunk) {
            $sources[] = [
                'label' => $label,
                'url' => $chunk->url(),
            ];
        }

        return $result->withCitations($sources, true);
    }

    private function recordCitationErrors(ToolRunResult $result, CitationReport $report): void
    {
        $callId = $result->modelCallIds[array_key_last($result->modelCallIds)] ?? 0;

        if (! $callId) {
            return;
        }

        ModelCall::query()
            ->whereKey($callId)
            ->update(['citation_errors' => $report->errorCount()]);
    }

    private function withoutCallIds(array $messages): array
    {
        return array_map(function (array $message): array {
            unset($message['model_call_id']);

            return $message;
        }, $messages);
    }

    private function recordSources(Conversation $conversation, ToolRunResult $result, AssembledPrompt $assembled): void
    {
        if ($result->sources === []) {
            return;
        }

        $cited = array_intersect_key($assembled->labelMap, array_flip(array_column($result->sources, 'label')));

        $this->store->recordSources($this->store->lastReply($conversation), $cited);
    }

    private function assemble(User $user, string $question, Conversation $conversation, int $allowance, array $chunks): AssembledPrompt
    {
        return SupportPrompt::assemble(
            question: $question,
            user: $user,
            summary: $conversation->summary,
            history: $this->store->history($conversation, $allowance),
            contextChunks: $chunks,
        );
    }

    private function answerFromCache(Conversation $conversation, string $question, CacheLookup $lookup, int|float $started): ToolRunResult
    {
        $hit = $lookup->answer;

        $event = new ModelCalled(
            feature: 'support.reply',
            provider: self::CACHE_PROVIDER,
            model: self::CACHE_MODEL,
            usage: [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
            ],
            finishReason: 'stop',
            latencyMs: (int) ((hrtime(true) - $started) / 1_000_000),
            conversationId: $conversation->id,
            promptVersion: SupportPrompt::VERSION,
            semanticCacheHit: true,
        );

        event($event);

        $this->cache->recordHit($hit);

        $result = new ToolRunResult(
            response: $this->cachedResponse($hit, $event),
            messages: [],
            rounds: 0,
            modelCallIds: [$event->modelCallId],
        );

        $this->remember($conversation, $question, $result);

        return $result->forConversation($conversation->id);
    }

    private function cachedResponse(CachedAnswer $hit, ModelCalled $event): ChatResponse
    {
        return new ChatResponse(
            id: "cached_answer:{$hit->id}",
            model: self::CACHE_MODEL,
            content: $hit->answer,
            toolCalls: [],
            finishReason: 'stop',
            usage: new Usage(promptTokens: 0, completionTokens: 0),
            raw: [
                'semantic_cache' => [
                    'cached_answer_id' => $hit->id,
                    'similarity' => (float) $hit->similarity,
                    'cached_question' => $hit->question,
                ],
            ],
            modelCallId: $event->modelCallId,
        );
    }

    private function remember(Conversation $conversation, string $question, ToolRunResult $result): void
    {
        $this->store->append($conversation, [
            'role' => Message::ROLE_USER,
            'content' => $question,
        ]);

        $this->store->appendRun($conversation, $result);
    }

    private function summarizeInBackground(Conversation $conversation, int $allowance): void
    {
        $fresh = $conversation->fresh();

        $needed = $this->store->needsSummary($fresh, $allowance) || $this->store->needsCompaction($fresh);

        SummarizeConversation::dispatchIf($needed, $fresh, $allowance);
    }
}
