<?php

namespace App\Ai\Load;

use App\Ai\Conversations\ReplayScript;
use App\Ai\Replies\ReplyDispatcher;
use App\Models\Conversation;
use App\Models\ModelCall;
use App\Models\Reply;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Sleep;
use Throwable;

final class QueuedReplay
{
    private const POLL_MILLISECONDS = 250;

    private const ANSWER_FEATURE = 'support.reply';

    public function __construct(
        private readonly ReplyDispatcher $replies,
        private readonly CurrentTenant $tenant,
    ) {}

    public function run(User $user, ReplayScript $script, int $users, int $timeoutSeconds): ReplayLoadResult
    {
        $simulated = array_map(fn (): SimulatedUser => new SimulatedUser, range(1, $users));

        $startedAt = CarbonImmutable::now();

        $started = hrtime(true);

        $this->tenant->runAs($user->customer_id, fn () => $this->drive($user, $script, $simulated, $started, $timeoutSeconds));

        $wallSeconds = (hrtime(true) - $started) / 1_000_000_000;

        return $this->resultOf($simulated, count($script->messages), $wallSeconds, $startedAt);
    }

    private function drive(User $user, ReplayScript $script, array $simulated, int|float $started, int $timeoutSeconds): void
    {
        while (! $this->allFinished($simulated)) {
            if ($this->hasTimedOut($started, $timeoutSeconds)) {
                $this->timeOut($simulated);

                return;
            }

            if (! $this->advanceAll($user, $script, $simulated)) {
                Sleep::for(self::POLL_MILLISECONDS)->milliseconds();
            }
        }
    }

    private function advanceAll(User $user, ReplayScript $script, array $simulated): bool
    {
        $progressed = false;

        foreach ($simulated as $state) {
            if ($this->advance($user, $script, $state)) {
                $progressed = true;
            }
        }

        return $progressed;
    }

    private function advance(User $user, ReplayScript $script, SimulatedUser $state): bool
    {
        if ($state->isFinished()) {
            return false;
        }

        if ($state->reply === null) {
            $this->submit($user, $script, $state);

            return true;
        }

        $state->reply->refresh();

        return $this->settle($script, $state);
    }

    private function submit(User $user, ReplayScript $script, SimulatedUser $state): void
    {
        try {
            $submission = $this->replies->submit(
                user: $user,
                question: $script->messages[$state->nextTurn],
                conversation: $state->conversation,
            );
        } catch (Throwable $exception) {
            $state->outcome = ReplayLoadResult::FAILED;

            $state->error = $exception->getMessage();

            return;
        }

        $state->reply = $submission->reply;

        $state->conversation ??= Conversation::query()->find($submission->reply->conversation_id);
    }

    private function settle(ReplayScript $script, SimulatedUser $state): bool
    {
        $status = $state->reply->status;

        if ($status === Reply::STATUS_COMPLETED) {
            $this->completeTurn($script, $state);

            return true;
        }

        if (in_array($status, [Reply::STATUS_FAILED, Reply::STATUS_EXPIRED], true)) {
            $state->outcome = ReplayLoadResult::FAILED;

            return true;
        }

        if ($status === Reply::STATUS_PAUSED) {
            $state->outcome = ReplayLoadResult::PAUSED;

            return true;
        }

        return false;
    }

    private function completeTurn(ReplayScript $script, SimulatedUser $state): void
    {
        $state->completedTurns++;

        $state->nextTurn++;

        $state->reply = null;

        if ($state->nextTurn >= count($script->messages)) {
            $state->outcome = ReplayLoadResult::FINISHED;
        }
    }

    private function allFinished(array $simulated): bool
    {
        foreach ($simulated as $state) {
            if (! $state->isFinished()) {
                return false;
            }
        }

        return true;
    }

    private function hasTimedOut(int|float $started, int $timeoutSeconds): bool
    {
        return (hrtime(true) - $started) / 1_000_000_000 >= $timeoutSeconds;
    }

    private function timeOut(array $simulated): void
    {
        foreach ($simulated as $state) {
            if (! $state->isFinished()) {
                $state->outcome = ReplayLoadResult::TIMED_OUT;
            }
        }
    }

    private function resultOf(array $simulated, int $turns, float $wallSeconds, CarbonImmutable $startedAt): ReplayLoadResult
    {
        $calls = ModelCall::query()
            ->whereIn('conversation_id', $this->conversationIds($simulated))
            ->where('feature', self::ANSWER_FEATURE)
            ->where('created_at', '>=', $startedAt->subSecond())
            ->get();

        return new ReplayLoadResult(
            users: $simulated,
            turnsPerUser: $turns,
            wallSeconds: $wallSeconds,
            ttftMilliseconds: $calls->pluck('ttft_ms')->filter(fn (mixed $value): bool => $value !== null)->map(fn (mixed $value): int => (int) $value)->values()->all(),
            completionTokens: (int) $calls->sum('completion_tokens'),
        );
    }

    private function conversationIds(array $simulated): array
    {
        $ids = [];

        foreach ($simulated as $state) {
            if ($state->conversation !== null) {
                $ids[] = $state->conversation->id;
            }
        }

        return $ids;
    }
}
