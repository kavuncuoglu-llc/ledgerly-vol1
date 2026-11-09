<?php

namespace Tests\Unit\Ai\Support;

use App\Ai\Events\ModelCalled;
use App\Ai\Prompts\TicketClassifyPrompt;
use App\Ai\Support\ClassifyTicket;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClassifyTicketTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Event::fake([ModelCalled::class]);
    }

    public function test_invoke_sends_the_prompt_messages_with_the_deterministic_profile_and_a_four_token_cap(): void
    {
        Http::fake(['*' => Http::response($this->completion('billing'))]);

        ($this->classify())('My card was charged twice');

        Http::assertSent(fn (Request $request): bool => $request['messages'] === TicketClassifyPrompt::messages('My card was charged twice')
            && $request['temperature'] === 0
            && $request['seed'] === 42
            && $request['max_tokens'] === 4
            && $request['stop'] === ["\n"]);
    }

    public function test_invoke_returns_the_label_the_model_replied_with(): void
    {
        Http::fake(['*' => Http::response($this->completion('billing'))]);

        $label = ($this->classify())('My card was charged twice');

        $this->assertSame('billing', $label);
    }

    public function test_invoke_trims_and_lowercases_the_reply(): void
    {
        Http::fake(['*' => Http::response($this->completion("  Billing \n"))]);

        $label = ($this->classify())('My card was charged twice');

        $this->assertSame('billing', $label);
    }

    #[DataProvider('unexpectedReplies')]
    public function test_invoke_falls_back_to_other_when_the_reply_is_not_an_allowed_label(string $reply): void
    {
        Http::fake(['*' => Http::response($this->completion($reply))]);

        $label = ($this->classify())('My card was charged twice');

        $this->assertSame('other', $label);
    }

    public static function unexpectedReplies(): array
    {
        return [
            'a sentence' => ['This looks like a billing question'],
            'a label with punctuation' => ['billing.'],
            'an unknown label' => ['urgent'],
            'an empty reply' => [''],
        ];
    }

    public function test_invoke_logs_the_call_under_ticket_classify_with_the_prompt_version(): void
    {
        Http::fake(['*' => Http::response($this->completion('bug'))]);

        ($this->classify())('Export is broken');

        Event::assertDispatched(ModelCalled::class, fn (ModelCalled $event): bool => $event->feature === 'ticket.classify'
            && $event->promptVersion === TicketClassifyPrompt::VERSION);
    }

    private function classify(): ClassifyTicket
    {
        return app(ClassifyTicket::class);
    }

    private function completion(string $content): array
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
                'prompt_tokens' => 120,
                'completion_tokens' => 1,
            ],
        ];
    }
}
