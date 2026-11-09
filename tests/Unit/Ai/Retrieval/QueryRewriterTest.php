<?php

namespace Tests\Unit\Ai\Retrieval;

use App\Ai\Http\AiHttpClient;
use App\Ai\Retrieval\QueryRewriter;
use App\Ai\Retrieval\RewriteMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\SeedsHelpChunks;
use Tests\TestCase;

class QueryRewriterTest extends TestCase
{
    use RefreshDatabase;
    use SeedsHelpChunks;

    private const QUESTION = 'my client keeps paying me late, what can I do';

    public function test_queries_puts_the_original_first_then_the_rewrite(): void
    {
        $this->fakeChat('"automatic reminders and late fees for overdue customer invoices"');

        $queries = $this->rewriter(RewriteMode::Rewrite)->queries(self::QUESTION);

        $this->assertSame([self::QUESTION, 'automatic reminders and late fees for overdue customer invoices'], $queries);
    }

    public function test_queries_calls_the_model_once_at_the_deterministic_profile_with_the_mode_token_cap(): void
    {
        $this->fakeChat('reminders');

        $this->rewriter(RewriteMode::Rewrite)->queries(self::QUESTION);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request['max_tokens'] === 60
            && $request['temperature'] === 0
            && $request['messages'][1]['content'] === self::QUESTION
            && str_contains($request['messages'][0]['content'], 'documentation register'));
    }

    public function test_queries_expands_a_json_array_into_variants(): void
    {
        $this->fakeChat('["set up payment reminders", "charge a late fee", "options when a customer pays late"]');

        $queries = $this->rewriter(RewriteMode::Expand)->queries(self::QUESTION);

        $this->assertCount(4, $queries);
        $this->assertSame('charge a late fee', $queries[2]);
    }

    public function test_queries_expands_a_bulleted_list_when_the_model_ignores_json(): void
    {
        $this->fakeChat("1. set up payment reminders\n2. charge a late fee\n- options when a customer pays late");

        $queries = $this->rewriter(RewriteMode::Expand)->queries(self::QUESTION);

        $this->assertSame(['set up payment reminders', 'charge a late fee', 'options when a customer pays late'], array_slice($queries, 1));
    }

    public function test_queries_uses_the_hypothetical_answer_paragraph_for_hyde(): void
    {
        $this->fakeChat("You can enable automatic payment reminders\nthat email the client after the due date.");

        $queries = $this->rewriter(RewriteMode::Hyde)->queries(self::QUESTION);

        $this->assertSame('You can enable automatic payment reminders that email the client after the due date.', $queries[1]);
        Http::assertSent(fn (Request $request): bool => $request['max_tokens'] === 160);
    }

    public function test_queries_drops_a_rewrite_identical_to_the_question(): void
    {
        $this->fakeChat('MY CLIENT KEEPS PAYING ME LATE, WHAT CAN I DO');

        $this->assertSame([self::QUESTION], $this->rewriter(RewriteMode::Rewrite)->queries(self::QUESTION));
    }

    public function test_queries_skips_the_model_for_identifier_questions(): void
    {
        Http::fake();

        $queries = $this->rewriter(RewriteMode::Rewrite)->queries('status of invoice 4471');

        $this->assertSame(['status of invoice 4471'], $queries);
        Http::assertNothingSent();
    }

    public function test_queries_skips_the_model_when_rewriting_is_off(): void
    {
        Http::fake();

        $queries = $this->rewriter(RewriteMode::Off)->queries(self::QUESTION);

        $this->assertSame([self::QUESTION], $queries);
        Http::assertNothingSent();
    }

    public function test_queries_sends_the_configured_rewrite_model(): void
    {
        $this->fakeChat('reminders');

        $this->rewriter(RewriteMode::Rewrite, model: 'llama3.2:3b')->queries(self::QUESTION);

        Http::assertSent(fn (Request $request): bool => $request['model'] === 'llama3.2:3b');
    }

    public function test_queries_records_the_call_under_the_retrieval_rewrite_feature(): void
    {
        $this->fakeChat('reminders');

        $this->rewriter(RewriteMode::Rewrite)->queries(self::QUESTION);

        $this->assertDatabaseHas('model_calls', [
            'feature' => 'retrieval.rewrite',
        ]);
    }

    private function fakeChat(string $content): void
    {
        Http::fake(['*' => Http::response($this->chatReply($content))]);
    }

    private function rewriter(RewriteMode $mode, ?string $model = null): QueryRewriter
    {
        return new QueryRewriter(app(AiHttpClient::class), $mode, $model);
    }
}
