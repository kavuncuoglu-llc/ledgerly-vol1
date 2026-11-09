<?php

namespace App\Ai\Memory;

use App\Ai\Prompts\ExtractMemoriesPrompt;
use App\Ai\Schemas\ExtractedMemories;
use App\Ai\Schemas\ExtractedMemory;
use App\Ai\Structured\StructuredClient;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\UserMemory;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

final class ExtractMemories implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly Conversation $conversation) {}

    public function handle(StructuredClient $structured, MemoryStore $memories, StatedFactGuard $guard, CurrentTenant $tenant): void
    {
        $tenant->runAs(
            $this->conversation->tenant_id,
            fn () => $this->extract($structured, $memories, $guard),
        );
    }

    private function extract(StructuredClient $structured, MemoryStore $memories, StatedFactGuard $guard): void
    {
        $user = User::find($this->conversation->user_id);

        if ($user === null) {
            return;
        }

        $turns = $this->spokenTurns();

        if ($turns->where('role', Message::ROLE_USER)->isEmpty()) {
            return;
        }

        $extracted = $structured->extract(
            class: ExtractedMemories::class,
            messages: ExtractMemoriesPrompt::messages($turns->all()),
            options: [
                'max_tokens' => 400,
            ],
            feature: 'memory.extract',
            promptVersion: ExtractMemoriesPrompt::VERSION,
            conversationId: $this->conversation->id,
        );

        $customerText = $turns->where('role', Message::ROLE_USER)->pluck('content')->implode("\n");

        foreach ($extracted->facts as $fact) {
            $this->remember($memories, $guard, $user, $fact, $customerText);
        }
    }

    private function remember(MemoryStore $memories, StatedFactGuard $guard, User $user, ExtractedMemory $fact, string $customerText): void
    {
        if ($fact->confidence < (float) config('ai.memory.min_confidence')) {
            return;
        }

        if (! $guard->allows($fact->key, $fact->value, $customerText)) {
            return;
        }

        $existing = $memories->find($user, $fact->key);

        if (! $existing->exists) {
            $memories->create($user, $fact->key, $fact->value, UserMemory::SOURCE_EXTRACTED, $this->conversation->id);

            return;
        }

        $memories->update($existing, $fact->value, UserMemory::SOURCE_EXTRACTED, $this->conversation->id);
    }

    private function spokenTurns(): Collection
    {
        return Message::query()
            ->where('conversation_id', $this->conversation->id)
            ->whereIn('role', [Message::ROLE_USER, Message::ROLE_ASSISTANT])
            ->whereNotNull('content')
            ->orderBy('seq')
            ->get(['role', 'content'])
            ->map(fn (Message $message): array => [
                'role' => $message->role,
                'content' => (string) $message->content,
            ]);
    }
}
