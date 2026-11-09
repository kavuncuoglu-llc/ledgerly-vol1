<?php

namespace App\Ai\Replies;

use App\Ai\Conversations\ConversationStore;
use App\Ai\Prompts\SupportPrompt;
use App\Models\Conversation;
use App\Models\Reply;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

final class ReplyDispatcher
{
    public function __construct(
        private readonly ConversationStore $conversations,
    ) {}

    public function submit(User $user, string $question, ?Conversation $conversation = null, string $requestKey = ''): ReplySubmission
    {
        $existing = $this->existing($user, $requestKey);

        if ($existing->exists) {
            return new ReplySubmission($existing, $this->queueDepth());
        }

        $reply = $this->createReply($user, $question, $conversation, $requestKey);

        AnswerQuestion::dispatch($reply->id);

        $depth = $this->queueDepth();

        $this->warnWhenBacklogged($depth);

        return new ReplySubmission($reply, $depth);
    }

    private function createReply(User $user, string $question, ?Conversation $conversation, string $requestKey): Reply
    {
        $conversation ??= $this->conversations->start($user, SupportPrompt::VERSION);

        try {
            return Reply::create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'request_key' => $requestKey === ''
                    ? null
                    : $requestKey,
                'question' => $question,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            return $this->existing($user, $requestKey);
        }
    }

    private function existing(User $user, string $requestKey): Reply
    {
        if ($requestKey === '') {
            return new Reply;
        }

        return Reply::query()
            ->where('user_id', $user->id)
            ->where('request_key', $requestKey)
            ->firstOrNew();
    }

    private function queueDepth(): int
    {
        return Queue::size(config('ai.replies.queue'));
    }

    private function warnWhenBacklogged(int $depth): void
    {
        if ($depth < (int) config('ai.replies.queue_depth_alert')) {
            return;
        }

        Log::warning('The ai queue is backing up.', [
            'depth' => $depth,
            'queue' => config('ai.replies.queue'),
        ]);
    }
}
