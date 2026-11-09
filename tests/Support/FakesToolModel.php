<?php

namespace Tests\Support;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;

trait FakesToolModel
{
    protected function customerUser(): User
    {
        return User::factory()
            ->forCustomer(Customer::factory()->create())
            ->create();
    }

    protected function invoiceFor(User $user, string $number = 'INV-4471', int $overdueBy = 18): Invoice
    {
        return Invoice::factory()
            ->overdueBy($overdueBy)
            ->create([
                'customer_id' => $user->customer_id,
                'number' => $number,
            ]);
    }

    protected function toolCall(string $id, string $name, array $arguments): array
    {
        return [
            'id' => $id,
            'type' => 'function',
            'function' => [
                'name' => $name,
                'arguments' => json_encode($arguments),
            ],
        ];
    }

    protected function toolCallResponse(array $toolCalls): array
    {
        return [
            'id' => 'chatcmpl-tools',
            'model' => 'llama3.1:8b',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => $toolCalls,
                    ],
                    'finish_reason' => 'tool_calls',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 40,
                'completion_tokens' => 9,
            ],
        ];
    }

    protected function answerResponse(string $content): array
    {
        return [
            'id' => 'chatcmpl-answer',
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
                'prompt_tokens' => 80,
                'completion_tokens' => 20,
            ],
        ];
    }
}
