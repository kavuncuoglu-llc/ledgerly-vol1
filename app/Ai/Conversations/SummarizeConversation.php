<?php

namespace App\Ai\Conversations;

use App\Ai\Exceptions\SummarizationFailed;
use App\Ai\Http\AiHttpClient;
use App\Ai\Prompts\SummarizePrompt;
use App\Models\Conversation;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

final class SummarizeConversation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly int $allowance,
        public readonly bool $force = false,
    ) {}

    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(AiHttpClient $client, ConversationStore $store, CurrentTenant $tenant): void
    {
        $tenant->runAs(
            $this->conversation->tenant_id,
            fn () => $this->compactThenSummarize($client, $store),
        );
    }

    private function compactThenSummarize(AiHttpClient $client, ConversationStore $store): void
    {
        $conversation = $this->conversation->fresh();

        if ($conversation === null) {
            return;
        }

        $store->compact($conversation);

        if (! $this->shouldSummarize($store, $conversation)) {
            return;
        }

        $toSummarize = $this->withinInputCap($store->exchangesBetween(
            $conversation,
            $conversation->summary_through_seq,
            $store->cutoffSeq($conversation, (int) config('ai.conversation.keep_recent_exchanges')),
        ));

        if ($toSummarize->isEmpty()) {
            return;
        }

        $this->storeSummary($client, $conversation, $toSummarize);
    }

    private function shouldSummarize(ConversationStore $store, Conversation $conversation): bool
    {
        if ($this->force) {
            return true;
        }

        return $store->needsSummary($conversation, $this->allowance);
    }

    private function storeSummary(AiHttpClient $client, Conversation $conversation, Collection $toSummarize): void
    {
        $response = $client->chat(
            SummarizePrompt::messages(existing: $conversation->summary, exchanges: $toSummarize),
            [
                'profile' => 'deterministic',
                'max_tokens' => config('ai.conversation.summary_max_tokens'),
            ],
            'conversation.summarize',
            SummarizePrompt::VERSION,
            conversationId: $conversation->id,
        );

        $summary = trim((string) $response->content);

        if ($summary === '') {
            throw new SummarizationFailed($conversation->id);
        }

        $conversation->update([
            'summary' => $summary,
            'summary_through_seq' => $toSummarize->last()->lastSeq(),
        ]);
    }

    private function withinInputCap(Collection $exchanges): Collection
    {
        $cap = (int) config('ai.conversation.summary_input_tokens');

        $spent = 0;

        return $exchanges->takeWhile(function (Exchange $exchange) use ($cap, &$spent): bool {
            $spent += $exchange->tokens();

            return $spent <= $cap;
        })->whenEmpty(fn (Collection $kept): Collection => $exchanges->take(1));
    }
}
