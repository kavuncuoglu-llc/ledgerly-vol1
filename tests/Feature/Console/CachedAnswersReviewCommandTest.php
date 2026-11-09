<?php

namespace Tests\Feature\Console;

use App\Models\CachedAnswer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakesSemanticCache;
use Tests\TestCase;

class CachedAnswersReviewCommandTest extends TestCase
{
    use FakesSemanticCache;
    use RefreshDatabase;

    public function test_it_lists_automatic_answers_only(): void
    {
        $this->cachedAnswer(['tenant_id' => 3, 'question' => 'Waiting for review?']);
        $this->cachedAnswer(['tenant_id' => 3, 'question' => 'Already approved?', 'status' => CachedAnswer::STATUS_APPROVED]);
        $this->cachedAnswer(['tenant_id' => 3, 'question' => 'Already rejected?', 'status' => CachedAnswer::STATUS_REJECTED]);

        $this->artisan('cached-answers:review')
            ->expectsOutputToContain('Waiting for review?')
            ->doesntExpectOutputToContain('Already approved?')
            ->doesntExpectOutputToContain('Already rejected?')
            ->assertSuccessful();
    }

    public function test_it_says_when_nothing_waits_for_review(): void
    {
        $this->artisan('cached-answers:review')
            ->expectsOutput('No automatic answers waiting for review.')
            ->assertSuccessful();
    }

    public function test_approve_keeps_the_answer_private_to_its_tenant(): void
    {
        $row = $this->cachedAnswer(['tenant_id' => 3]);

        $this->artisan('cached-answers:review', ['--approve' => (string) $row->id])
            ->expectsOutput('1 approved.')
            ->assertSuccessful();

        $this->assertSame(CachedAnswer::STATUS_APPROVED, $row->fresh()->status);
        $this->assertSame(3, $row->fresh()->tenant_id);
    }

    public function test_approve_with_share_makes_the_answer_visible_to_every_tenant(): void
    {
        $row = $this->cachedAnswer(['tenant_id' => 3]);

        $this->artisan('cached-answers:review', ['--approve' => (string) $row->id, '--share' => true])
            ->expectsOutput('1 approved and shared across tenants.')
            ->assertSuccessful();

        $this->assertSame(CachedAnswer::STATUS_APPROVED, $row->fresh()->status);
        $this->assertNull($row->fresh()->tenant_id);
    }

    public function test_share_refuses_an_answer_that_carries_account_details(): void
    {
        $row = $this->cachedAnswer([
            'tenant_id' => 3,
            'answer' => 'Invoice INV-4471 for acme@example.com is overdue by eighteen days.',
        ]);

        $this->artisan('cached-answers:review', ['--approve' => (string) $row->id, '--share' => true])
            ->expectsOutputToContain('Refusing to share')
            ->assertFailed();

        $this->assertSame(CachedAnswer::STATUS_AUTO, $row->fresh()->status);
        $this->assertSame(3, $row->fresh()->tenant_id);
    }

    public function test_share_refuses_a_question_that_points_at_an_account(): void
    {
        $row = $this->cachedAnswer([
            'tenant_id' => 3,
            'question' => 'Why is my March invoice late?',
        ]);

        $this->artisan('cached-answers:review', ['--approve' => (string) $row->id, '--share' => true])
            ->assertFailed();

        $this->assertSame(3, $row->fresh()->tenant_id);
    }

    public function test_reject_marks_the_answers_rejected(): void
    {
        $first = $this->cachedAnswer(['tenant_id' => 3]);
        $second = $this->cachedAnswer(['tenant_id' => 3]);

        $this->artisan('cached-answers:review', ['--reject' => "{$first->id},{$second->id}"])
            ->expectsOutput('2 rejected.')
            ->assertSuccessful();

        $this->assertSame(0, CachedAnswer::query()->where('status', CachedAnswer::STATUS_AUTO)->count());
    }
}
