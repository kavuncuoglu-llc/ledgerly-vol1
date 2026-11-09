<?php

namespace Tests\Feature\Support;

use App\Ai\Prompts\SupportPrompt;
use App\Models\Conversation;
use App\Models\ModelCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class SupportAskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        RateLimiter::clear('ai');
    }

    public function test_ask_returns_the_answer_and_sends_the_system_prompt_with_the_question(): void
    {
        Http::fake(['*' => Http::response($this->completion('A late fee is an extra charge.'))]);

        $response = $this->actingAs($this->user())->postJson('support/ask', [
            'question' => 'What is a late fee?',
        ]);

        $response->assertOk()->assertExactJson([
            'answer' => 'A late fee is an extra charge.',
            'conversation_id' => Conversation::query()->sole()->id,
            'truncated' => false,
            'rounds' => 1,
            'hit_cap' => false,
            'pending' => [],
        ]);
        Http::assertSent(fn (Request $request): bool => $request['messages'] === SupportPrompt::messages('What is a late fee?')
            && $request['max_tokens'] === 400
            && $request['temperature'] === 0.7);
    }

    public function test_ask_flags_a_reply_that_stopped_on_the_length_limit(): void
    {
        Http::fake(['*' => Http::response($this->completion('The fee is 1.5% of the', 'length'))]);

        $response = $this->actingAs($this->user())->postJson('support/ask', [
            'question' => 'What is a late fee?',
        ]);

        $response->assertOk()->assertJson(['truncated' => true]);
    }

    public function test_ask_records_the_call_in_model_calls_under_support_reply(): void
    {
        Http::fake(['*' => Http::response($this->completion('ok'))]);

        $this->actingAs($this->user())->postJson('support/ask', ['question' => 'Hi']);

        $call = ModelCall::query()->sole();
        $this->assertSame('support.reply', $call->feature);
        $this->assertSame(31, $call->prompt_tokens);
        $this->assertSame(2, $call->completion_tokens);
        $this->assertSame('stop', $call->finish_reason);
    }

    public function test_ask_records_the_support_prompt_version_on_the_model_calls_row(): void
    {
        Http::fake(['*' => Http::response($this->completion('ok'))]);

        $this->actingAs($this->user())->postJson('support/ask', ['question' => 'Hi']);

        $this->assertSame(SupportPrompt::VERSION, ModelCall::query()->sole()->prompt_version);
    }

    public function test_ask_sends_no_context_yet_so_the_assistant_is_told_none_was_provided(): void
    {
        Http::fake(['*' => Http::response($this->completion('ok'))]);

        $this->actingAs($this->user())->postJson('support/ask', ['question' => 'What late fee does Ledgerly charge?']);

        Http::assertSent(fn (Request $request): bool => str_contains(
            $request['messages'][1]['content'],
            "Context:\n(no context provided)\n\nQuestion: What late fee does Ledgerly charge?",
        ));
    }

    public function test_ask_rejects_a_guest_with_401(): void
    {
        Http::fake();

        $response = $this->postJson('support/ask', ['question' => 'Hi']);

        $response->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_ask_rejects_a_guest_with_401_even_without_a_json_accept_header(): void
    {
        Http::fake();

        $response = $this->post('support/ask', ['question' => 'Hi']);

        $response->assertUnauthorized();
    }

    public function test_ask_requires_a_question(): void
    {
        Http::fake();

        $response = $this->actingAs($this->user())->postJson('support/ask', []);

        $response->assertUnprocessable()->assertJsonValidationErrors('question');
        Http::assertNothingSent();
    }

    public function test_ask_rejects_a_question_that_is_not_a_string(): void
    {
        Http::fake();

        $response = $this->actingAs($this->user())->postJson('support/ask', ['question' => ['a']]);

        $response->assertUnprocessable()->assertJsonValidationErrors('question');
    }

    public function test_ask_rejects_a_question_over_the_length_limit(): void
    {
        Http::fake();

        $response = $this->actingAs($this->user())->postJson('support/ask', [
            'question' => str_repeat('a', 2001),
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('question');
    }

    public function test_ask_is_throttled_per_user(): void
    {
        config()->set('ai.rate_limit', 2);
        Http::fake(['*' => Http::response($this->completion('ok'))]);
        $user = $this->user();

        $this->actingAs($user)->postJson('support/ask', ['question' => 'one'])->assertOk();
        $this->actingAs($user)->postJson('support/ask', ['question' => 'two'])->assertOk();
        $response = $this->actingAs($user)->postJson('support/ask', ['question' => 'three']);

        $response->assertTooManyRequests();
    }

    public function test_ask_reports_a_failing_model_server_as_a_server_error(): void
    {
        Http::fake(['*' => Http::response('model not found', 404)]);

        $response = $this->actingAs($this->user())->postJson('support/ask', ['question' => 'Hi']);

        $response->assertServerError();
        $this->assertSame(0, ModelCall::query()->count());
    }

    private function user(): User
    {
        return User::factory()->create();
    }

    private function completion(string $content, string $finishReason = 'stop'): array
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
                    'finish_reason' => $finishReason,
                ],
            ],
            'usage' => [
                'prompt_tokens' => 31,
                'completion_tokens' => 2,
            ],
        ];
    }
}
