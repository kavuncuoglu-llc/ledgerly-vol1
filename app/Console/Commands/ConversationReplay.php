<?php

namespace App\Console\Commands;

use App\Ai\Conversations\ConversationStore;
use App\Ai\Conversations\ReplayScript;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\UnreadableReplayScript;
use App\Ai\Load\QueuedReplay;
use App\Ai\Load\ReplayLoadResult;
use App\Ai\Memory\MemoryStore;
use App\Ai\Support\SupportAssistant;
use App\Models\Conversation;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Console\Command;

class ConversationReplay extends Command
{
    private const DEFAULT_USER_EMAIL = 'acme@example.com';

    protected $signature = 'conversation:replay {script : Path to a replay script such as storage/samples/long-conversation.json} {--user= : User id or email (default: acme@example.com)} {--close : Close the conversation afterwards and extract memories} {--users= : Replay the script as this many simultaneous users through the ai queue and report time to first token and throughput} {--timeout=600 : Seconds to wait for the simulated users with --users}';

    protected $description = 'Replay a scripted customer conversation through the support assistant and print the prompt size and summary state per turn, or with --users drive N simulated users through the ai queue';

    public function handle(SupportAssistant $assistant, ConversationStore $store, MemoryStore $memories, CurrentTenant $tenant, QueuedReplay $queued): int
    {
        $user = $this->resolveUser();

        if ($user === null) {
            $this->error('No such user.');

            return Command::FAILURE;
        }

        if ($this->option('users') !== null) {
            return $this->replayAsUsers($queued, $user);
        }

        try {
            $script = ReplayScript::fromFile((string) $this->argument('script'));

            config()->set('queue.default', 'sync');

            $tenant->runAs($user->customer_id, fn () => $this->replay($assistant, $store, $memories, $user, $script));
        } catch (UnreadableReplayScript|ModelRequestFailed $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function replayAsUsers(QueuedReplay $queued, User $user): int
    {
        $users = (int) $this->option('users');

        if ($users < 1) {
            $this->error('--users must be a whole number of at least 1.');

            return Command::FAILURE;
        }

        try {
            $script = ReplayScript::fromFile((string) $this->argument('script'));
        } catch (UnreadableReplayScript $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->announceQueue($users);

        $result = $queued->run($user, $script, $users, (int) $this->option('timeout'));

        $this->line($result->line());

        $this->line($result->detail());

        if ($result->firstError() !== '') {
            $this->error('First error: '.$result->firstError());
        }

        return $result->count(ReplayLoadResult::FINISHED) === $users
            ? Command::SUCCESS
            : Command::FAILURE;
    }

    private function announceQueue(int $users): void
    {
        $connection = (string) config('queue.default');

        $this->line("{$users} simulated users through the ai queue on the {$connection} connection");

        if ($connection !== 'sync') {
            return;
        }

        $this->warn('The queue connection is sync, so each reply runs inside this process one after another. Use a real queue connection with Horizon running (AI_CONCURRENCY workers) to see the server batch.');
    }

    private function replay(SupportAssistant $assistant, ConversationStore $store, MemoryStore $memories, User $user, ReplayScript $script): void
    {
        $conversation = new Conversation;

        foreach ($script->messages as $index => $message) {
            $conversation = $this->takeTurn($assistant, $store, $user, $message, $conversation, $index + 1);
        }

        $this->askFollowUp($assistant, $user, $script, $conversation);

        $this->closeIfAsked($store, $memories, $user, $conversation);
    }

    private function takeTurn(SupportAssistant $assistant, ConversationStore $store, User $user, string $message, Conversation $conversation, int $turn): Conversation
    {
        $result = $assistant->answer(
            user: $user,
            question: $message,
            conversation: $conversation->exists
                ? $conversation
                : null,
        );

        $current = $store->find($result->conversationId);

        $this->line(sprintf(
            'turn %d prompt %s summary: %s',
            $turn,
            number_format($result->response->usage->promptTokens),
            $this->summaryState($current),
        ));

        return $current;
    }

    private function summaryState(Conversation $conversation): string
    {
        if ($conversation->summary === null) {
            return 'no';
        }

        return "yes (through seq {$conversation->summary_through_seq})";
    }

    private function askFollowUp(SupportAssistant $assistant, User $user, ReplayScript $script, Conversation $conversation): void
    {
        if ($script->followUp === '') {
            return;
        }

        $result = $assistant->answer($user, $script->followUp, $conversation);

        $this->line('');
        $this->line('Q: '.$script->followUp);
        $this->line('A: '.$result->answer());
    }

    private function closeIfAsked(ConversationStore $store, MemoryStore $memories, User $user, Conversation $conversation): void
    {
        if (! $this->option('close')) {
            return;
        }

        $store->close($conversation);

        $this->line('');
        $this->line('Memories now stored for this user:');

        foreach ($memories->all($user) as $memory) {
            $this->line("- {$memory->key}: {$memory->value}");
        }
    }

    private function resolveUser(): ?User
    {
        $given = (string) ($this->option('user') ?: self::DEFAULT_USER_EMAIL);

        if (ctype_digit($given)) {
            return User::find((int) $given);
        }

        return User::query()->where('email', $given)->first();
    }
}
