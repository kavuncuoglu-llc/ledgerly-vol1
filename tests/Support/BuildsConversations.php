<?php

namespace Tests\Support;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;

trait BuildsConversations
{
    protected function userInTenant(?Customer $customer = null): User
    {
        return User::factory()
            ->forCustomer($customer ?? Customer::factory()->create())
            ->create();
    }

    protected function conversationFor(User $user): Conversation
    {
        return Conversation::factory()->forUser($user)->create();
    }

    protected function addMessage(Conversation $conversation, string $role, ?string $content, int $tokens = 10, array $extra = []): Message
    {
        $seq = (int) Message::query()
            ->withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->max('seq') + 1;

        return Message::create([
            'conversation_id' => $conversation->id,
            'seq' => $seq,
            'role' => $role,
            'content' => $content,
            'tokens' => $tokens,
            ...$extra,
        ]);
    }

    protected function addExchange(Conversation $conversation, string $question, string $reply, int $tokensEach = 10): void
    {
        $this->addMessage($conversation, 'user', $question, $tokensEach);

        $this->addMessage($conversation, 'assistant', $reply, $tokensEach);
    }

    protected function addToolExchange(Conversation $conversation, string $question, string $toolName, string $toolContent, string $reply, int $toolTokens = 300, string $callId = 'call_1'): void
    {
        $this->addMessage($conversation, 'user', $question, 10);

        $this->addMessage($conversation, 'assistant', null, 20, [
            'tool_calls' => [
                [
                    'id' => $callId,
                    'type' => 'function',
                    'function' => [
                        'name' => $toolName,
                        'arguments' => '{}',
                    ],
                ],
            ],
        ]);

        $this->addMessage($conversation, 'tool', $toolContent, $toolTokens, [
            'tool_call_id' => $callId,
        ]);

        $this->addMessage($conversation, 'assistant', $reply, 10);
    }

    protected function completion(string $content, int $promptTokens = 80, int $completionTokens = 20): array
    {
        return [
            'id' => 'chatcmpl-1',
            'model' => 'llama3.1:8b',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => $content,
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
            ],
        ];
    }
}
