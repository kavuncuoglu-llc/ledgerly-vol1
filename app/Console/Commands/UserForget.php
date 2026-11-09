<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\PendingToolCall;
use App\Models\RecordedRequest;
use App\Models\ToolCall;
use App\Models\User;
use App\Models\UserMemory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserForget extends Command
{
    protected $signature = 'user:forget {user : The id of the user to forget} {--confirm : Actually delete; without it nothing changes}';

    protected $description = 'Delete a user\'s conversations, messages, memories and tool-call content; keep only anonymized model_calls counts';

    public function handle(): int
    {
        $user = User::find($this->argument('user'));

        if ($user === null) {
            $this->error('No user with that id.');

            return Command::FAILURE;
        }

        if (! $this->option('confirm')) {
            $this->warn('Nothing deleted. Re-run with --confirm to delete this user\'s conversation content.');

            return Command::FAILURE;
        }

        $counts = DB::transaction(fn (): array => $this->forget($user));

        $this->report($counts);

        Log::info('user:forget', ['user_id' => $user->id, ...$counts]);

        return Command::SUCCESS;
    }

    private function forget(User $user): array
    {
        $conversationIds = Conversation::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->pluck('id');

        return [
            'recorded_requests' => RecordedRequest::query()->whereIn('conversation_id', $conversationIds->all())->delete(),
            'anonymized_model_calls' => $this->anonymizeModelCalls($conversationIds->all()),
            'messages' => $this->deleteMessages($conversationIds->all()),
            'conversations' => Conversation::query()->withoutGlobalScopes()->whereKey($conversationIds)->delete(),
            'memories' => UserMemory::query()->withoutGlobalScopes()->where('user_id', $user->id)->delete(),
            'tool_calls' => ToolCall::query()->where('user_id', $user->id)->delete(),
            'pending_approvals' => PendingToolCall::query()->where('user_id', $user->id)->delete(),
        ];
    }

    private function anonymizeModelCalls(array $conversationIds): int
    {
        return ModelCall::query()
            ->whereIn('conversation_id', $conversationIds)
            ->update(['conversation_id' => null]);
    }

    private function deleteMessages(array $conversationIds): int
    {
        return Message::query()
            ->withoutGlobalScopes()
            ->whereIn('conversation_id', $conversationIds)
            ->delete();
    }

    private function report(array $counts): void
    {
        $this->line("Deleted: {$counts['conversations']} conversations, {$counts['messages']} messages, {$counts['memories']} memories, {$counts['tool_calls']} tool_call rows with content, {$counts['pending_approvals']} pending approvals, {$counts['recorded_requests']} recorded requests. Anonymized: {$counts['anonymized_model_calls']} model_calls rows (kept counts). Logged.");
    }
}
