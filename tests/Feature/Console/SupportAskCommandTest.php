<?php

namespace Tests\Feature\Console;

use App\Models\CachedAnswer;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\FakesSemanticCache;
use Tests\Support\SeedsHelpChunks;
use Tests\TestCase;

class SupportAskCommandTest extends TestCase
{
    use FakesSemanticCache;
    use RefreshDatabase;
    use SeedsHelpChunks;

    private const QUESTION = 'How do I set up recurring invoices?';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.semantic_cache.enabled', true);
        Sleep::fake();
        $this->disableIndexScans();
    }

    public function test_it_prints_the_models_answer_on_a_miss(): void
    {
        $user = $this->customerUser();
        $this->fakeSemanticStack([self::QUESTION => $this->unitVector(0)], answer: 'Open Recurring and press New recurring invoice.');

        $this->artisan('support:ask', ['question' => self::QUESTION, '--user' => $user->id])
            ->expectsOutput('Open Recurring and press New recurring invoice.')
            ->assertSuccessful();

        $this->assertSame(1, CachedAnswer::query()->count());
    }

    public function test_it_prints_the_sources_the_answer_cited(): void
    {
        $user = $this->customerUser();
        $this->seedChunk('set-up-recurring-invoice', 'How do I create a recurring schedule?', 'Open Recurring and choose New recurring invoice to create a schedule.', $this->unitVector(0), category: 'recurring');
        $this->fakeSemanticStack([self::QUESTION => $this->unitVector(0)], answer: 'Open Recurring and choose New recurring invoice [set-up-recurring-invoice › How do I create a recurring schedule?].');

        $this->artisan('support:ask', ['question' => self::QUESTION, '--user' => $user->id])
            ->expectsOutputToContain('source: [set-up-recurring-invoice › How do I create a recurring schedule?] /help/recurring/set-up-recurring-invoice#how-do-i-create-a-recurring-schedule')
            ->assertSuccessful();
    }

    public function test_it_names_the_cached_question_and_similarity_on_a_hit(): void
    {
        $user = $this->customerUser();
        $this->cachedAnswer([
            'tenant_id' => $user->customer_id,
            'answer' => 'Open Recurring and press New recurring invoice.',
        ]);
        $this->fakeSemanticStack(['Can I make an invoice repeat every month?' => $this->vectorWithCosine(0, 1, 0.94)]);

        $this->artisan('support:ask', ['question' => 'Can I make an invoice repeat every month?', '--user' => $user->id])
            ->expectsOutput('[semantic cache hit · 0.94 · cached question: "How do I set up recurring invoices?"]')
            ->expectsOutput('Open Recurring and press New recurring invoice.')
            ->assertSuccessful();
    }

    public function test_it_accepts_a_user_by_email(): void
    {
        $user = $this->customerUser();
        $this->fakeSemanticStack([], answer: 'An answer that is long enough to be stored here.');

        $this->artisan('support:ask', ['question' => self::QUESTION, '--user' => $user->email])->assertSuccessful();
    }

    public function test_it_fails_for_an_unknown_user(): void
    {
        Http::fake();

        $this->artisan('support:ask', ['question' => self::QUESTION, '--user' => 'nobody@example.com'])
            ->expectsOutput('No such user.')
            ->assertFailed();
    }

    public function test_it_reports_an_unreachable_model_instead_of_a_stack_trace(): void
    {
        $user = $this->customerUser();
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->artisan('support:ask', ['question' => self::QUESTION, '--user' => $user->id])
            ->expectsOutputToContain('HTTP 500')
            ->assertFailed();
    }

    private function customerUser(): User
    {
        return User::factory()->forCustomer(Customer::factory()->create())->create();
    }
}
