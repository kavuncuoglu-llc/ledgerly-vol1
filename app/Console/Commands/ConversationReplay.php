<?php

namespace App\Console\Commands;

use App\Ai\Conversations\ConversationStore;
use App\Ai\Conversations\ReplayScript;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\UnreadableReplayScript;
use App\Ai\Memory\MemoryStore;
use App\Ai\Support\SupportAssistant;
use App\Models\Conversation;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Console\Command;

class ConversationReplay extends Command
{
    private const DEFAULT_USER_EMAIL = 'acme@example.com';

    protected $signature = 'conversation:replay {script : Path to a replay script such as storage/samples/long-conversation.json} {--user= : User id or email (default: acme@example.com)} {--close : Close the conversation afterwards and extract memories}';

    protected $description = 'Replay a scripted customer conversation through the support assistant and print the prompt size and summary state per turn';

    public function handle(SupportAssistant $assistant, ConversationStore $store, MemoryStore $memories, CurrentTenant $tenant): int
    {
        $user = $this->resolveUser();

        if ($user === null) {
            $this->error('No such user.');

            return Command::FAILURE;
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
