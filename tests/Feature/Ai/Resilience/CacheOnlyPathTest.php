<?php

namespace Tests\Feature\Ai\Resilience;

use App\Ai\Prompts\SupportPrompt;
use App\Models\ModelCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Tests\Support\FakesSemanticCache;
use Tests\Support\FakesToolModel;
use Tests\TestCase;

class CacheOnlyPathTest extends TestCase
{
    use FakesSemanticCache;
    use FakesToolModel;
    use RefreshDatabase;

    private const QUESTION = 'How do I set up recurring invoices?';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.semantic_cache.enabled', true);
        Sleep::fake();
        RateLimiter::clear('ai');
        $this->disableIndexScans();
    }

    public function test_a_cached_how_to_answer_is_served_while_the_chat_model_is_down(): void
    {
        $user = $this->customerUser();
        $this->cachedAnswer(['tenant_id' => $user->customer_id, 'answer' => 'Open Recurring and choose New recurring invoice.']);

        Http::fake(fn (Request $request) => str_ends_with($request->url(), '/embeddings')
            ? Http::response(['data' => [['embedding' => $this->unitVector(0)]]])
            : Http::response('model server is down', 503));

        $this->actingAs($user)->postJson('support/ask', ['question' => self::QUESTION])
            ->assertOk()
            ->assertJson(['answer' => 'Open Recurring and choose New recurring invoice.', 'rounds' => 0]);

        $this->assertSame('semantic-cache', ModelCall::query()->latest('id')->first()->provider);
    }

    public function test_an_uncached_question_gets_the_honest_message_when_the_classifier_and_the_chat_model_are_down(): void
    {
        $user = $this->customerUser();

        Http::fake(fn (Request $request) => str_ends_with($request->url(), '/embeddings')
            ? Http::response(['data' => [['embedding' => $this->unitVector(5)]]])
            : Http::response('model server is down', 503));

        $this->actingAs($user)->postJson('support/ask', ['question' => self::QUESTION])
            ->assertOk()
            ->assertJson(['answer' => SupportPrompt::unavailableMessage()]);

        $this->assertSame('degraded', ModelCall::query()->where('feature', 'support.reply')->sole()->provider);
    }

    public function test_when_the_embedding_model_is_down_too_the_question_still_gets_the_honest_message_not_an_error(): void
    {
        $user = $this->customerUser();

        Http::fake(['*' => Http::response('everything is down', 503)]);

        $this->actingAs($user)->postJson('support/ask', ['question' => self::QUESTION])
            ->assertOk()
            ->assertJson(['answer' => SupportPrompt::unavailableMessage()]);
    }
}
