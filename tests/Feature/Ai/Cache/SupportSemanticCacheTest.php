<?php

namespace Tests\Feature\Ai\Cache;

use App\Ai\Memory\MemoryStore;
use App\Models\CachedAnswer;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Tests\Support\FakesSemanticCache;
use Tests\Support\FakesToolModel;
use Tests\TestCase;

class SupportSemanticCacheTest extends TestCase
{
    use FakesSemanticCache;
    use FakesToolModel;
    use RefreshDatabase;

    private const QUESTION = 'How do I set up recurring invoices?';

    private const ANSWER = 'Open Recurring, choose New recurring invoice, pick an interval and save the schedule.';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.semantic_cache.enabled', true);
        Sleep::fake();
        RateLimiter::clear('ai');
        $this->disableIndexScans();
    }

    public function test_a_first_ask_calls_the_model_and_stores_the_answer_for_the_tenant(): void
    {
        $user = $this->customerUser();
        $this->fakeSemanticStack([self::QUESTION => $this->unitVector(0)], answer: self::ANSWER);

        $this->actingAs($user)->postJson('support/ask', ['question' => self::QUESTION])->assertOk();

        $stored = CachedAnswer::query()->sole();
        $this->assertSame($user->customer_id, $stored->tenant_id);
        $this->assertSame(self::ANSWER, $stored->answer);
        $this->assertSame('howto', $stored->category);
        $this->assertSame('auto', $stored->status);
        $this->assertFalse(ModelCall::query()->where('feature', 'support.reply')->sole()->semantic_cache_hit);
        $this->assertNull(ModelCall::query()->where('feature', 'ticket.classify')->sole()->semantic_cache_hit);
    }

    public function test_the_same_question_in_a_new_conversation_is_answered_without_calling_the_model(): void
    {
        $user = $this->customerUser();
        $this->fakeSemanticStack([self::QUESTION => $this->unitVector(0)], answer: self::ANSWER);
        $this->actingAs($user)->postJson('support/ask', ['question' => self::QUESTION]);
        $modelCallsBefore = ModelCall::query()->count();
        $requestsBefore = count(Http::recorded());

        $response = $this->actingAs($user)->postJson('support/ask', ['question' => self::QUESTION]);

        $response->assertOk()->assertJson([
            'answer' => self::ANSWER,
            'rounds' => 0,
        ]);
        $this->assertCount($requestsBefore + 1, Http::recorded());
        $this->assertTrue(str_ends_with(Http::recorded()->last()[0]->url(), '/embeddings'));
        $hit = ModelCall::query()->latest('id')->first();
        $this->assertSame($modelCallsBefore + 1, ModelCall::query()->count());
        $this->assertTrue($hit->semantic_cache_hit);
        $this->assertSame('support.reply', $hit->feature);
        $this->assertSame('semantic-cache', $hit->provider);
        $this->assertSame(0, $hit->prompt_tokens);
        $this->assertSame(1, CachedAnswer::query()->sole()->hit_count);
    }

    public function test_a_hit_is_stored_in_the_new_conversation_so_a_follow_up_can_refer_to_it(): void
    {
        $user = $this->customerUser();
        $this->cachedAnswer([
            'tenant_id' => $user->customer_id,
            'answer' => self::ANSWER,
        ]);
        $this->fakeSemanticStack([self::QUESTION => $this->unitVector(0)]);

        $response = $this->actingAs($user)->postJson('support/ask', ['question' => self::QUESTION]);

        $conversation = Conversation::query()->withoutGlobalScopes()->findOrFail($response->json('conversation_id'));
        $messages = Message::query()->withoutGlobalScopes()->where('conversation_id', $conversation->id)->orderBy('seq')->get();
        $this->assertSame(['user', 'assistant'], $messages->pluck('role')->all());
        $this->assertSame(self::ANSWER, $messages[1]->content);
        $this->assertSame(ModelCall::query()->latest('id')->first()->id, $messages[1]->model_call_id);
    }

    public function test_a_rephrased_question_above_the_threshold_also_hits(): void
    {
        $user = $this->customerUser();
        $this->cachedAnswer(['tenant_id' => $user->customer_id]);
        $this->fakeSemanticStack(['Can I make an invoice repeat every month?' => $this->vectorWithCosine(0, 1, 0.95)]);

        $response = $this->actingAs($user)->postJson('support/ask', ['question' => 'Can I make an invoice repeat every month?']);

        $response->assertOk()->assertJson(['rounds' => 0]);
        $this->assertTrue(ModelCall::query()->latest('id')->first()->semantic_cache_hit);
    }

    public function test_a_hit_does_not_need_the_classifier_so_it_makes_exactly_one_embedding_request(): void
    {
        $user = $this->customerUser();
        $this->cachedAnswer(['tenant_id' => $user->customer_id]);
        $this->fakeSemanticStack([self::QUESTION => $this->unitVector(0)]);

        $this->actingAs($user)->postJson('support/ask', ['question' => self::QUESTION]);

        Http::assertSentCount(1);
    }

    public function test_another_tenant_asking_the_same_question_never_receives_the_first_tenants_answer(): void
    {
        $first = $this->customerUser();
        $second = $this->customerUser();
        $this->cachedAnswer([
            'tenant_id' => $first->customer_id,
            'answer' => 'ANSWER WRITTEN FOR THE FIRST TENANT',
        ]);
        $this->fakeSemanticStack([self::QUESTION => $this->unitVector(0)], answer: 'A fresh answer from the model for the second tenant.');

        $response = $this->actingAs($second)->postJson('support/ask', ['question' => self::QUESTION]);

        $response->assertOk()->assertJson(['answer' => 'A fresh answer from the model for the second tenant.']);
        $this->assertSame(0, CachedAnswer::query()->where('tenant_id', $first->customer_id)->sole()->hit_count);
    }

    public function test_an_account_specific_question_skips_the_cache_entirely(): void
    {
        $user = $this->customerUser();
        $this->cachedAnswer(['tenant_id' => $user->customer_id]);
        $this->fakeSemanticStack([], answer: 'Invoice details are in your account.');

        $this->actingAs($user)->postJson('support/ask', ['question' => 'Why is my March invoice late?'])->assertOk();

        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/embeddings'));
        Http::assertSentCount(1);
        $this->assertNull(ModelCall::query()->where('feature', 'support.reply')->sole()->semantic_cache_hit);
        $this->assertSame(1, CachedAnswer::query()->count());
    }

    public function test_a_question_the_classifier_does_not_call_how_to_is_answered_but_not_cached(): void
    {
        $user = $this->customerUser();
        $this->fakeSemanticStack([self::QUESTION => $this->unitVector(0)], classification: 'billing', answer: self::ANSWER);

        $this->actingAs($user)->postJson('support/ask', ['question' => self::QUESTION])->assertOk();

        $this->assertSame(0, CachedAnswer::query()->count());
        $this->assertNull(ModelCall::query()->where('feature', 'support.reply')->sole()->semantic_cache_hit);
    }

    public function test_an_escape_hatch_answer_is_never_cached(): void
    {
        $user = $this->customerUser();
        $this->fakeSemanticStack([self::QUESTION => $this->unitVector(0)], answer: "I don't have that information, but I can open a support ticket for you.");

        $this->actingAs($user)->postJson('support/ask', ['question' => self::QUESTION])->assertOk();

        $this->assertSame(0, CachedAnswer::query()->count());
        $this->assertFalse(ModelCall::query()->where('feature', 'support.reply')->sole()->semantic_cache_hit);
    }

    public function test_an_answer_written_with_the_users_memories_in_the_prompt_is_never_cached(): void
    {
        $user = $this->customerUser();
        app(MemoryStore::class)->create($user, 'role', 'bookkeeper, not the owner', 'stated');
        $this->fakeSemanticStack([self::QUESTION => $this->unitVector(0)], answer: self::ANSWER);

        $this->actingAs($user)->postJson('support/ask', ['question' => self::QUESTION])->assertOk();

        $this->assertSame(0, CachedAnswer::query()->count());
    }

    public function test_an_answer_that_needed_a_tool_call_is_never_cached(): void
    {
        $user = $this->customerUser();
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/embeddings')) {
                return Http::response(['data' => [['embedding' => $this->unitVector(0)]]]);
            }

            if (isset($request['response_format'])) {
                return Http::response($this->chatBody(json_encode([
                    'category' => 'howto',
                    'invoice_number' => null,
                    'customer_email' => null,
                    'amount_mentioned' => null,
                    'date_mentioned' => null,
                ])));
            }

            if (collect($request['messages'])->contains('role', 'tool')) {
                return Http::response($this->answerResponse('You have no invoices matching that yet, so nothing is overdue at the moment.'));
            }

            return Http::response($this->toolCallResponse([$this->toolCall('call_1', 'list_invoices', ['status' => null, 'month' => null, 'months' => 6])]));
        });

        $response = $this->actingAs($user)->postJson('support/ask', ['question' => self::QUESTION]);

        $response->assertOk()->assertJson(['rounds' => 2]);
        $this->assertSame(0, CachedAnswer::query()->count());
    }

    public function test_a_follow_up_turn_in_an_existing_conversation_never_touches_the_cache(): void
    {
        $user = $this->customerUser();
        $this->fakeSemanticStack([self::QUESTION => $this->unitVector(0)], answer: 'Hello there, how can I help you today?');
        $conversationId = $this->actingAs($user)->postJson('support/ask', ['question' => 'Hi there'])->json('conversation_id');
        $this->cachedAnswer(['tenant_id' => $user->customer_id]);
        $this->fakeSemanticStack([self::QUESTION => $this->unitVector(0)], answer: 'A model answer to the follow-up question.');

        $response = $this->actingAs($user)->postJson('support/ask', [
            'question' => self::QUESTION,
            'conversation_id' => $conversationId,
        ]);

        $response->assertOk()->assertJson(['answer' => 'A model answer to the follow-up question.']);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/embeddings'));
    }

    public function test_a_user_without_a_customer_account_neither_reads_nor_writes_the_cache(): void
    {
        $user = User::factory()->create();
        $this->cachedAnswer([
            'tenant_id' => null,
            'status' => CachedAnswer::STATUS_APPROVED,
        ]);
        $this->fakeSemanticStack([self::QUESTION => $this->unitVector(0)], answer: self::ANSWER);

        $response = $this->actingAs($user)->postJson('support/ask', ['question' => self::QUESTION]);

        $response->assertOk()->assertJson(['rounds' => 1]);
        $this->assertSame(1, CachedAnswer::query()->count());
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/embeddings'));
    }

    public function test_an_unclassifiable_question_is_still_answered_by_the_model(): void
    {
        $user = $this->customerUser();
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/embeddings')) {
                return Http::response(['data' => [['embedding' => $this->unitVector(0)]]]);
            }

            if (isset($request['response_format'])) {
                return Http::response($this->chatBody('{"category":"urgent"}'));
            }

            return Http::response($this->chatBody(self::ANSWER));
        });

        $response = $this->actingAs($user)->postJson('support/ask', ['question' => self::QUESTION]);

        $response->assertOk()->assertJson(['answer' => self::ANSWER]);
        $this->assertSame(0, CachedAnswer::query()->count());
    }

    public function test_the_cache_is_switched_off_by_config(): void
    {
        config()->set('ai.semantic_cache.enabled', false);
        $user = $this->customerUser();
        $this->cachedAnswer(['tenant_id' => $user->customer_id]);
        Http::fake(['*' => Http::response($this->chatBody('A model answer, because the cache is off.'))]);

        $response = $this->actingAs($user)->postJson('support/ask', ['question' => self::QUESTION]);

        $response->assertOk()->assertJson(['answer' => 'A model answer, because the cache is off.']);
        Http::assertSentCount(1);
    }

    public function test_grounding_can_be_required_before_anything_is_stored(): void
    {
        config()->set('ai.semantic_cache.require_grounding', true);
        $user = $this->customerUser();
        $this->fakeSemanticStack([self::QUESTION => $this->unitVector(0)], answer: self::ANSWER);

        $this->actingAs($user)->postJson('support/ask', ['question' => self::QUESTION])->assertOk();

        $this->assertSame(0, CachedAnswer::query()->count());
    }
}
