<?php

namespace Tests\Feature\Ai\Prompts;

use App\Ai\Http\AiHttpClient;
use App\Ai\Prompts\TicketClassifyPrompt;
use App\Ai\Support\ClassifyTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('model')]
#[Group('fake-model')]
class TicketClassifyBehaviorTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_MODEL_LABELS = [
        'My card was charged twice' => 'billing',
        'How do I add a logo?' => 'howto',
        'Export is broken' => 'bug',
        'I forgot my password' => 'account',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    #[DataProvider('messages')]
    public function test_the_client_returns_an_allowed_label_for_the_fixed_inputs(string $message): void
    {
        $this->fakeScriptedModel();

        $output = app(AiHttpClient::class)->chat(
            messages: TicketClassifyPrompt::messages($message),
            options: [
                'profile' => 'deterministic',
                'max_tokens' => 4,
            ],
        )->content;

        $this->assertContains(trim($output), TicketClassifyPrompt::LABELS);
    }

    #[DataProvider('messages')]
    public function test_the_action_returns_the_label_the_fake_model_was_scripted_to_give(string $message): void
    {
        $this->fakeScriptedModel();

        $label = app(ClassifyTicket::class)($message);

        $this->assertSame(self::FAKE_MODEL_LABELS[$message], $label);
    }

    public static function messages(): array
    {
        return array_map(
            fn (string $message): array => [$message],
            array_combine(array_keys(self::FAKE_MODEL_LABELS), array_keys(self::FAKE_MODEL_LABELS)),
        );
    }

    public function test_the_action_still_returns_an_allowed_label_when_the_fake_model_misbehaves(): void
    {
        Http::fake(['*' => Http::response($this->completion('I think this is a Billing question, sorry!'))]);

        $label = app(ClassifyTicket::class)('My card was charged twice');

        $this->assertContains($label, TicketClassifyPrompt::LABELS);
        $this->assertSame('other', $label);
    }

    private function fakeScriptedModel(): void
    {
        Http::fake(fn (Request $request) => Http::response($this->completion($this->fakeModelReply($request))));
    }

    private function fakeModelReply(Request $request): string
    {
        $messages = $request['messages'];

        $lastUserTurn = end($messages)['content'];

        return self::FAKE_MODEL_LABELS[$lastUserTurn] ?? 'other';
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
