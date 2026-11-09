<?php

namespace App\Ai\Conversations;

use App\Ai\Exceptions\ConversationNotOpen;
use App\Ai\Memory\ExtractMemories;
use App\Ai\Tokens\TokenCounter;
use App\Ai\Tools\ToolRunResult;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageSource;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class EloquentConversationStore implements ConversationStore
{
    public function __construct(
        private readonly TokenCounter $counter,
        private readonly ToolResultCompactor $compactor,
    ) {}

    public function start(User $user, string $promptVersion): Conversation
    {
        return Conversation::create([
            'user_id' => $user->id,
            'tenant_id' => $user->customer_id,
            'status' => Conversation::STATUS_OPEN,
            'prompt_version' => $promptVersion,
        ]);
    }

    public function find(int $id): Conversation
    {
        return Conversation::query()->find($id) ?? new Conversation;
    }

    public function append(Conversation $c, array $message): Message
    {
        return DB::transaction(function () use ($c, $message): Message {
            Conversation::query()
                ->withoutGlobalScopes()
                ->whereKey($c->id)
                ->lockForUpdate()
                ->first();

            $stored = Message::create([
                'conversation_id' => $c->id,
                'seq' => $this->nextSeq($c),
                'role' => $message['role'],
                'content' => $message['content'] ?? null,
                'tool_calls' => $message['tool_calls'] ?? null,
                'tool_call_id' => $message['tool_call_id'] ?? null,
                'tokens' => $this->tokensFor($message),
                'model_call_id' => $message['model_call_id'] ?? null,
            ]);

            $c->touch();

            return $stored;
        });
    }

    public function appendRun(Conversation $c, ToolRunResult $run): void
    {
        if ($run->hasPending()) {
            $this->append($c, [
                'role' => Message::ROLE_ASSISTANT,
                'content' => $this->awaitingApprovalNote($run),
                'model_call_id' => $run->response->modelCallId,
            ]);

            return;
        }

        foreach ($run->newMessages() as $message) {
            $this->append($c, $message);
        }
    }

    public function lastReply(Conversation $c): Message
    {
        return Message::query()
            ->withoutGlobalScopes()
            ->where('conversation_id', $c->id)
            ->where('role', Message::ROLE_ASSISTANT)
            ->orderByDesc('seq')
            ->firstOrNew();
    }

    public function recordSources(Message $reply, array $cited): void
    {
        if (! $reply->exists) {
            return;
        }

        foreach ($cited as $label => $chunk) {
            MessageSource::query()->updateOrCreate(
                [
                    'message_id' => $reply->id,
                    'label' => $label,
                ],
                [
                    'help_chunk_id' => $chunk->id,
                    'source' => $chunk->source,
                    'heading_path' => $chunk->headingPath,
                    'help_version' => $chunk->helpVersion,
                ],
            );
        }
    }

    public function history(Conversation $c, int $budgetTokens): array
    {
        $allowance = max(0, $budgetTokens - $this->summaryTokens($c));

        return $this->window($c, $allowance, pinFirst: $c->summary_through_seq === 0);
    }

    public function window(Conversation $c, int $allowance, bool $pinFirst = true): array
    {
        $exchanges = $this->exchanges($c);

        $pinned = collect();

        $spent = 0;

        if ($pinFirst && $exchanges->isNotEmpty()) {
            $first = $exchanges->shift();

            if ($first->tokens() <= $allowance) {
                $pinned->push($first);

                $spent += $first->tokens();
            }
        }

        $recent = $this->newestThatFit($exchanges, $allowance - $spent);

        return $pinned
            ->concat($recent)
            ->flatMap(fn (Exchange $exchange): array => $exchange->toApiMessages())
            ->all();
    }

    public function summarize(Conversation $c): void
    {
        SummarizeConversation::dispatchSync($c, 0, true);
    }

    public function needsSummary(Conversation $c, int $allowance): bool
    {
        $trigger = config('ai.conversation.summary_trigger_tokens');

        return $this->unsummarizedTokens($c) > (int) ($trigger ?? $allowance);
    }

    public function exchangesBetween(Conversation $c, int $afterSeq, int $throughSeq): Collection
    {
        $messages = Message::query()
            ->where('conversation_id', $c->id)
            ->where('seq', '>', $afterSeq)
            ->where('seq', '<=', $throughSeq)
            ->orderBy('seq')
            ->get();

        return $this->group($messages);
    }

    public function cutoffSeq(Conversation $c, int $keepRecent): int
    {
        $exchanges = $this->exchanges($c);

        $summarizable = $exchanges->count() - $keepRecent;

        if ($summarizable <= 0) {
            return $c->summary_through_seq;
        }

        return $exchanges->values()->get($summarizable - 1)->lastSeq();
    }

    public function needsCompaction(Conversation $c): bool
    {
        return $this->compactionCandidates($c)->isNotEmpty();
    }

    public function compact(Conversation $c): int
    {
        $candidates = $this->compactionCandidates($c);

        foreach ($candidates as $candidate) {
            $candidate['message']->update([
                'content' => $candidate['stub'],
                'tokens' => $this->counter->count($candidate['stub']) + $this->overhead(),
            ]);
        }

        return $candidates->count();
    }

    public function close(Conversation $c): void
    {
        if (! $c->isOpen()) {
            throw new ConversationNotOpen($c->status);
        }

        $c->update([
            'status' => Conversation::STATUS_CLOSED,
        ]);

        ExtractMemories::dispatch($c);
    }

    public function exchanges(Conversation $c): Collection
    {
        $messages = Message::query()
            ->where('conversation_id', $c->id)
            ->where('seq', '>', $c->summary_through_seq)
            ->orderBy('seq')
            ->get();

        return $this->group($messages);
    }

    private function group(Collection $messages): Collection
    {
        $groups = [];

        foreach ($messages as $message) {
            if ($message->role === Message::ROLE_USER || $groups === []) {
                $groups[] = collect();
            }

            $groups[array_key_last($groups)]->push($message);
        }

        return collect($groups)->map(fn (Collection $group): Exchange => new Exchange($group));
    }

    private function newestThatFit(Collection $exchanges, int $allowance): array
    {
        $recent = [];

        $spent = 0;

        foreach ($exchanges->reverse() as $exchange) {
            if ($spent + $exchange->tokens() > $allowance) {
                break;
            }

            array_unshift($recent, $exchange);

            $spent += $exchange->tokens();
        }

        return $recent;
    }

    private function compactionCandidates(Conversation $c): Collection
    {
        $exchanges = $this->exchanges($c);

        $oldEnough = $exchanges->take(max(0, $exchanges->count() - (int) config('ai.conversation.compact_after_exchanges')));

        $candidates = collect();

        foreach ($oldEnough as $exchange) {
            $candidates = $candidates->concat($this->compactableIn($exchange));
        }

        return $candidates;
    }

    private function compactableIn(Exchange $exchange): Collection
    {
        $candidates = collect();

        foreach ($exchange->messages() as $message) {
            $candidate = $this->candidateFor($exchange, $message);

            if ($candidate === []) {
                continue;
            }

            $candidates->push($candidate);
        }

        return $candidates;
    }

    private function candidateFor(Exchange $exchange, Message $message): array
    {
        if ($message->role !== Message::ROLE_TOOL) {
            return [];
        }

        $toolName = $exchange->toolNameFor((string) $message->tool_call_id);

        if (! $this->compactor->isCompactable($toolName, (string) $message->content)) {
            return [];
        }

        $stub = $this->compactor->stubFor($toolName, (string) $message->content);

        if ($this->counter->count($stub) + $this->overhead() >= $message->tokens) {
            return [];
        }

        return [
            'message' => $message,
            'stub' => $stub,
        ];
    }

    private function nextSeq(Conversation $c): int
    {
        return (int) Message::query()
            ->withoutGlobalScopes()
            ->where('conversation_id', $c->id)
            ->max('seq') + 1;
    }

    private function unsummarizedTokens(Conversation $c): int
    {
        return (int) Message::query()
            ->where('conversation_id', $c->id)
            ->where('seq', '>', $c->summary_through_seq)
            ->sum('tokens');
    }

    private function summaryTokens(Conversation $c): int
    {
        if ($c->summary === null) {
            return 0;
        }

        return $this->counter->count($c->summary) + $this->overhead();
    }

    private function tokensFor(array $message): int
    {
        $text = (string) ($message['content'] ?? '');

        if (isset($message['tool_calls'])) {
            $text .= json_encode($message['tool_calls']);
        }

        return $this->counter->count($text) + $this->overhead();
    }

    private function overhead(): int
    {
        return (int) config('ai.conversation.message_overhead_tokens');
    }

    private function awaitingApprovalNote(ToolRunResult $run): string
    {
        $summaries = array_map(
            fn ($result): string => $result->summary,
            $run->pending,
        );

        return 'Waiting for the customer to approve: '.implode('; ', $summaries);
    }
}
