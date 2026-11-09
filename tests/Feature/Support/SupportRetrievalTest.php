<?php

namespace Tests\Feature\Support;

use App\Ai\Prompts\SupportPrompt;
use App\Models\CachedAnswer;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessageSource;
use App\Models\ModelCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Tests\Support\FakesSemanticCache;
use Tests\Support\FakesToolModel;
use Tests\Support\SeedsHelpChunks;
use Tests\TestCase;

class SupportRetrievalTest extends TestCase
{
    use FakesSemanticCache;
    use FakesToolModel;
    use RefreshDatabase;
    use SeedsHelpChunks;

    private const QUESTION = 'What late fee does Ledgerly charge?';

    private const CALCULATED = 'late-fees › How the fee is calculated';

    private const GOOD_ANSWER = 'Ledgerly charges 1.5% of the outstanding balance per month, prorated by day [late-fees › How the fee is calculated].';

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        RateLimiter::clear('ai');
        $this->disableVectorIndexScans();
        $this->seedChunk('late-fees', 'How the fee is calculated', 'The fee is 1.5% of the outstanding balance per month, prorated by day.', $this->axisVector(0), version: '7b2e9d0');
    }

    public function test_ask_answers_from_retrieved_passages_and_returns_checked_sources(): void
    {
        $this->fakeEmbeddingsAndChat([self::QUESTION => $this->axisVector(0)], [$this->chatReply(self::GOOD_ANSWER)]);

        $response = $this->actingAs($this->customerUser())->postJson('support/ask', ['question' => self::QUESTION]);

        $response->assertOk()->assertJson([
            'answer' => self::GOOD_ANSWER,
            'sources' => [
                [
                    'label' => self::CALCULATED,
                    'url' => '/help/billing/late-fees#how-the-fee-is-calculated',
                ],
            ],
            'citations_ok' => true,
        ]);
    }

    public function test_ask_puts_the_labelled_passages_before_the_question_in_the_prompt(): void
    {
        $this->fakeEmbeddingsAndChat([self::QUESTION => $this->axisVector(0)], [$this->chatReply(self::GOOD_ANSWER)]);

        $this->actingAs($this->customerUser())->postJson('support/ask', ['question' => self::QUESTION]);

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/chat/completions')) {
                return false;
            }

            $messages = $request['messages'];

            return str_contains($messages[1]['content'], '(version 7b2e9d0)')
                && str_contains($messages[1]['content'], '['.self::CALCULATED."]\nThe fee is 1.5%")
                && $messages[2]['content'] === self::QUESTION;
        });
    }

    public function test_ask_records_which_chunks_the_reply_used_and_a_clean_citation_check(): void
    {
        $this->fakeEmbeddingsAndChat([self::QUESTION => $this->axisVector(0)], [$this->chatReply(self::GOOD_ANSWER)]);

        $this->actingAs($this->customerUser())->postJson('support/ask', ['question' => self::QUESTION]);

        $reply = Message::query()->withoutGlobalScopes()->where('role', 'assistant')->sole();
        $source = MessageSource::query()->sole();
        $this->assertSame($reply->id, $source->message_id);
        $this->assertSame(self::CALCULATED, $source->label);
        $this->assertSame('help/billing/late-fees.md', $source->source);
        $this->assertSame('7b2e9d0', $source->help_version);
        $this->assertSame(0, ModelCall::query()->sole()->citation_errors);
    }

    public function test_ask_repairs_a_fabricated_citation_once(): void
    {
        $this->fakeEmbeddingsAndChat([self::QUESTION => $this->axisVector(0)], [
            $this->chatReply('Ledgerly charges 1.5% per month [late-fees › Invented section].'),
            $this->chatReply(self::GOOD_ANSWER),
        ]);

        $response = $this->actingAs($this->customerUser())->postJson('support/ask', ['question' => self::QUESTION]);

        $response->assertOk()->assertJson([
            'answer' => self::GOOD_ANSWER,
            'citations_ok' => true,
        ]);
        $chats = $this->chatRequests();
        $this->assertCount(2, $chats);
        $repair = end($chats[1]['messages']);
        $this->assertSame('user', $repair['role']);
        $this->assertStringContainsString('Citation [late-fees › Invented section] does not match any provided passage.', $repair['content']);
        $this->assertSame([1, 0], ModelCall::query()->orderBy('id')->pluck('citation_errors')->all());
    }

    public function test_ask_repair_does_not_send_internal_call_ids_to_the_model(): void
    {
        $this->fakeEmbeddingsAndChat([self::QUESTION => $this->axisVector(0)], [
            $this->chatReply('Ledgerly charges 2% per month [late-fees › How the fee is calculated].'),
            $this->chatReply(self::GOOD_ANSWER),
        ]);

        $this->actingAs($this->customerUser())->postJson('support/ask', ['question' => self::QUESTION]);

        $this->assertStringNotContainsString('model_call_id', json_encode($this->chatRequests()[1]['messages']));
    }

    public function test_ask_falls_back_to_the_escape_hatch_when_the_repair_fails_too(): void
    {
        $this->fakeEmbeddingsAndChat([self::QUESTION => $this->axisVector(0)], [
            $this->chatReply('Ledgerly charges 2% per month [late-fees › How the fee is calculated].'),
            $this->chatReply('Ledgerly charges 3% per month [late-fees › How the fee is calculated].'),
        ]);

        $response = $this->actingAs($this->customerUser())->postJson('support/ask', ['question' => self::QUESTION]);

        $response->assertOk()->assertJson([
            'answer' => SupportPrompt::escapeHatch(),
            'sources' => [],
            'citations_ok' => false,
        ]);
        $this->assertSame(SupportPrompt::escapeHatch(), Message::query()->withoutGlobalScopes()->where('role', 'assistant')->sole()->content);
        $this->assertSame(0, MessageSource::query()->count());
    }

    public function test_ask_flags_an_uncited_factual_sentence(): void
    {
        $this->fakeEmbeddingsAndChat([self::QUESTION => $this->axisVector(0)], [
            $this->chatReply('Ledgerly charges 1.5% per month.'),
            $this->chatReply(self::GOOD_ANSWER),
        ]);

        $response = $this->actingAs($this->customerUser())->postJson('support/ask', ['question' => self::QUESTION]);

        $response->assertJson(['citations_ok' => true, 'answer' => self::GOOD_ANSWER]);
        $this->assertStringContainsString('Uncited factual sentence', end($this->chatRequests()[1]['messages'])['content']);
    }

    public function test_ask_sends_no_passages_and_accepts_a_decline_when_nothing_is_above_the_floor(): void
    {
        $this->fakeEmbeddingsAndChat(['best pizza near me' => $this->axisVector(300)], [$this->chatReply('I can only help with Ledgerly questions. I can open a support ticket if you like.')]);

        $response = $this->actingAs($this->customerUser())->postJson('support/ask', ['question' => 'best pizza near me']);

        $response->assertOk()->assertJson(['sources' => [], 'citations_ok' => true]);
        $this->assertCount(2, $this->chatRequests()[0]['messages']);
    }

    public function test_ask_does_not_require_citations_for_an_answer_built_from_a_tool_result(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user);
        $this->fakeEmbeddingsAndChat(['Status of INV-4471?' => $this->axisVector(0)], [
            $this->toolCallResponse([$this->toolCall('call_1', 'lookup_invoice', ['number' => 'INV-4471'])]),
            $this->answerResponse('Invoice INV-4471 is overdue by 18 days.'),
        ]);

        $response = $this->actingAs($user)->postJson('support/ask', ['question' => 'Status of INV-4471?']);

        $response->assertOk()->assertJson([
            'answer' => 'Invoice INV-4471 is overdue by 18 days.',
            'citations_ok' => true,
        ]);
        $this->assertCount(2, $this->chatRequests());
    }

    public function test_ask_excludes_chunks_tagged_for_another_plan(): void
    {
        $this->seedChunk('enterprise-terms', 'Terms', 'Enterprise late fees are custom.', $this->axisVector(0), tags: ['area' => 'billing', 'plans' => ['business']], version: '7b2e9d0', position: 2);
        $user = User::factory()->forCustomer(Customer::factory()->create(['plan' => 'free']))->create();
        $this->fakeEmbeddingsAndChat([self::QUESTION => $this->axisVector(0)], [$this->chatReply(self::GOOD_ANSWER)]);

        $this->actingAs($user)->postJson('support/ask', ['question' => self::QUESTION]);

        $this->assertStringNotContainsString('enterprise-terms', json_encode($this->chatRequests()[0]['messages']));
    }

    public function test_ask_requires_authentication(): void
    {
        $this->postJson('support/ask', ['question' => self::QUESTION])->assertUnauthorized();
    }

    public function test_ask_validates_the_question(): void
    {
        $this->actingAs($this->customerUser())->postJson('support/ask', [])->assertUnprocessable();
    }

    public function test_a_grounded_answer_is_cached_when_grounding_is_required(): void
    {
        config()->set('ai.semantic_cache.enabled', true);
        config()->set('ai.semantic_cache.require_grounding', true);
        $question = 'How do I set up recurring invoices?';
        $this->seedChunk('set-up-recurring-invoice', 'How do I create a recurring schedule?', 'Open Recurring and choose New recurring invoice to create a schedule.', $this->unitVector(0), category: 'recurring', version: '7b2e9d0', position: 3);
        $this->fakeSemanticStack([$question => $this->unitVector(0)], answer: 'Open Recurring and choose New recurring invoice [set-up-recurring-invoice › How do I create a recurring schedule?].');

        $this->actingAs($this->customerUser())->postJson('support/ask', ['question' => $question])->assertOk()->assertJson(['citations_ok' => true]);

        $this->assertSame(1, CachedAnswer::query()->count());
    }

    public function test_an_ungrounded_answer_is_not_cached_when_grounding_is_required(): void
    {
        config()->set('ai.semantic_cache.enabled', true);
        config()->set('ai.semantic_cache.require_grounding', true);
        $question = 'How do I set up recurring invoices?';
        $this->fakeSemanticStack([$question => $this->unitVector(500)]);

        $this->actingAs($this->customerUser())->postJson('support/ask', ['question' => $question])->assertOk();

        $this->assertSame(0, CachedAnswer::query()->count());
    }

    public function test_the_shipped_config_requires_grounding_for_the_semantic_cache(): void
    {
        $config = require config_path('ai.php');

        $this->assertStringContainsString("env('AI_SEMANTIC_CACHE_REQUIRE_GROUNDING', true)", file_get_contents(config_path('ai.php')));
        $this->assertArrayHasKey('require_grounding', $config['semantic_cache']);
    }

    private function chatRequests(): array
    {
        return Http::recorded()
            ->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $request): bool => str_ends_with($request->url(), '/chat/completions'))
            ->map(fn (Request $request): array => $request->data())
            ->values()
            ->all();
    }
}
