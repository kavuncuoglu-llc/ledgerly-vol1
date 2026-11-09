<?php

namespace App\Ai\Replies;

use App\Ai\Conversations\ConversationStore;
use App\Ai\Tools\ToolRunner;
use App\Models\Conversation;
use App\Models\Reply;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class ResumeReply implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout;

    public int $tries;

    public array $backoff;

    public int $uniqueFor;

    public function __construct(
        public readonly string $replyId,
    ) {
        $this->timeout = (int) config('ai.replies.timeout');

        $this->tries = (int) config('ai.replies.tries');

        $this->backoff = config('ai.replies.backoff');

        $this->uniqueFor = (int) config('ai.replies.unique_for');

        $this->onQueue(config('ai.replies.queue'));
    }

    public function uniqueId(): string
    {
        return "resume:{$this->replyId}";
    }

    public function handle(ToolRunner $runner, ConversationStore $conversations, ReplyLifecycle $lifecycle, CurrentTenant $tenant): void
    {
        $reply = Reply::query()->withoutGlobalScopes()->find($this->replyId);

        if ($reply === null) {
            return;
        }

        if (! $this->isResumable($reply)) {
            return;
        }

        $conversation = Conversation::query()->withoutGlobalScopes()->findOrFail($reply->conversation_id);

        $tenant->runAs(
            $conversation->tenant_id,
            fn () => $this->resume($runner, $conversations, $lifecycle, $reply, $conversation),
        );
    }

    public function failed(Throwable $exception): void
    {
        report($exception);

        $reply = Reply::query()->withoutGlobalScopes()->find($this->replyId);

        if ($reply === null) {
            return;
        }

        app(ReplyLifecycle::class)->fail($reply, $exception->getMessage());
    }

    private function isResumable(Reply $reply): bool
    {
        return in_array($reply->status, [
            Reply::STATUS_RESUMING,
            Reply::STATUS_STREAMING,
        ], true);
    }

    private function resume(ToolRunner $runner, ConversationStore $conversations, ReplyLifecycle $lifecycle, Reply $reply, Conversation $conversation): void
    {
        $lifecycle->begin($reply, restarted: true);

        $stream = $lifecycle->stream($reply);

        $result = $runner
            ->resume((string) $reply->resume_token, $stream->taps())
            ->forConversation($conversation->id);

        $stream->flush();

        $conversations->appendRun($conversation, $result);

        if ($result->hasPending()) {
            $lifecycle->pause($reply, $result);

            return;
        }

        $lifecycle->complete($reply, $result);
    }
}
