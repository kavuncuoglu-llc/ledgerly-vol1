<?php

namespace App\Ai\Triage;

use App\Ai\Exceptions\EmbeddingFailed;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Http\AiHttpClient;
use App\Ai\Prompts\DraftReplyPrompt;
use App\Ai\Retrieval\RetrievalScope;
use App\Ai\Retrieval\Retriever;
use App\Enums\TicketCategory;
use App\Models\Ticket;
use Illuminate\Support\Facades\Log;

final class Drafter
{
    public function __construct(
        private readonly AiHttpClient $client,
        private readonly Retriever $retriever,
    ) {}

    public function draft(Ticket $ticket, TicketCategory $category, array $findings, string $area): string
    {
        $chunks = $this->passagesFor($ticket, $area);

        $response = $this->client->chat(
            messages: DraftReplyPrompt::messages($ticket, $category, $findings, $chunks),
            options: [
                'profile' => 'grounded',
                'max_tokens' => (int) config('ai.triage.draft_max_tokens'),
            ],
            feature: 'ticket.draft',
            promptVersion: DraftReplyPrompt::VERSION,
        );

        return trim((string) $response->content);
    }

    private function passagesFor(Ticket $ticket, string $area): array
    {
        try {
            return $this->retriever->retrieve(
                question: $ticket->body,
                scope: new RetrievalScope(
                    tenantId: $ticket->customer_id,
                    plan: (string) $ticket->customer?->plan,
                    area: $area,
                ),
                k: (int) config('ai.triage.retrieval_k'),
            );
        } catch (EmbeddingFailed|ModelRequestFailed $exception) {
            Log::warning('Ticket draft written without help passages', [
                'ticket' => $ticket->number,
                'reason' => $exception->getMessage(),
            ]);

            return [];
        }
    }
}
