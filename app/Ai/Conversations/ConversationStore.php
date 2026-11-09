<?php

namespace App\Ai\Conversations;

use App\Ai\Tools\ToolRunResult;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Collection;

interface ConversationStore
{
    public function start(User $user, string $promptVersion): Conversation;

    public function find(int $id): Conversation;

    public function append(Conversation $c, array $message): Message;

    public function appendRun(Conversation $c, ToolRunResult $run): void;

    public function lastReply(Conversation $c): Message;

    public function recordSources(Message $reply, array $cited): void;

    public function history(Conversation $c, int $budgetTokens): array;

    public function window(Conversation $c, int $allowance, bool $pinFirst = true): array;

    public function summarize(Conversation $c): void;

    public function needsSummary(Conversation $c, int $allowance): bool;

    public function exchanges(Conversation $c): Collection;

    public function exchangesBetween(Conversation $c, int $afterSeq, int $throughSeq): Collection;

    public function cutoffSeq(Conversation $c, int $keepRecent): int;

    public function needsCompaction(Conversation $c): bool;

    public function compact(Conversation $c): int;

    public function close(Conversation $c): void;
}
