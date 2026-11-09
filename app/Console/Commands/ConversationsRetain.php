<?php

namespace App\Console\Commands;

use App\Ai\Memory\MemoryStore;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ConversationsRetain extends Command
{
    protected $signature = 'conversations:retain';

    protected $description = 'Archive closed conversations past the retention period (messages deleted, counts kept) and expire unused memories';

    public function handle(MemoryStore $memories): int
    {
        $archived = $this->archiveExpiredConversations($this->cutoff());

        $expired = $memories->expireUnused();

        $this->info("Archived {$archived['conversations']} conversations ({$archived['messages']} messages deleted); expired {$expired} unused memories.");

        return Command::SUCCESS;
    }

    private function cutoff(): Carbon
    {
        return now()->subDays((int) config('ai.retention.closed_conversation_days', 90));
    }

    private function archiveExpiredConversations(Carbon $cutoff): array
    {
        $totals = [
            'conversations' => 0,
            'messages' => 0,
        ];

        Conversation::query()
            ->withoutGlobalScopes()
            ->where('status', Conversation::STATUS_CLOSED)
            ->where('updated_at', '<', $cutoff)
            ->chunkById(500, function (Collection $chunk) use (&$totals): void {
                $totals['messages'] += $this->archive($chunk);

                $totals['conversations'] += $chunk->count();
            });

        return $totals;
    }

    private function archive(Collection $conversations): int
    {
        $deleted = Message::query()
            ->withoutGlobalScopes()
            ->whereIn('conversation_id', $conversations->modelKeys())
            ->delete();

        Conversation::query()
            ->withoutGlobalScopes()
            ->whereKey($conversations->modelKeys())
            ->update([
                'summary' => null,
                'title' => null,
                'summary_through_seq' => 0,
                'status' => Conversation::STATUS_ARCHIVED,
                'updated_at' => now(),
            ]);

        return $deleted;
    }
}
