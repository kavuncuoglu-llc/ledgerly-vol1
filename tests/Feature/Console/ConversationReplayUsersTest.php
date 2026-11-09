<?php

namespace Tests\Feature\Console;

use App\Models\Conversation;
use App\Models\ModelCall;
use App\Models\Reply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsConversations;
use Tests\Support\FakesStreamedModel;
use Tests\TestCase;

class ConversationReplayUsersTest extends TestCase
{
    use BuildsConversations;
    use FakesStreamedModel;
    use RefreshDatabase;

    private string $script;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        $this->script = tempnam(sys_get_temp_dir(), 'replay');

        file_put_contents($this->script, json_encode(['messages' => ['one', 'two', 'three']]));
    }

    private function finishPendingReplies(string $status, bool $onlyTheFirst = false): void
    {
        $pending = Reply::query()
            ->withoutGlobalScopes()
            ->where('status', Reply::STATUS_PENDING)
            ->orderBy('created_at')
            ->get();

        foreach ($pending as $index => $reply) {
            $reply->update([
                'status' => $onlyTheFirst && $index > 0
                    ? Reply::STATUS_COMPLETED
                    : $status,
            ]);
        }
    }

    protected function tearDown(): void
    {
        unlink($this->script);

        parent::tearDown();
    }

    public function test_each_simulated_user_gets_its_own_conversation_and_every_turn_goes_through_the_reply_job(): void
    {
        Http::fake(['*' => fn () => Http::response($this->streamedAnswer('Seven days.', completionTokens: 20))]);
        $user = $this->userInTenant();

        $this->artisan('conversation:replay', ['script' => $this->script, '--user' => $user->id, '--users' => 3])
            ->expectsOutputToContain('3 simulated users through the ai queue on the sync connection')
            ->expectsOutputToContain('0 failures · aggregate')
            ->expectsOutputToContain('3 users finished, 0 failed, 0 waiting for an approval, 0 timed out')
            ->assertSuccessful();

        $this->assertSame(3, Conversation::query()->withoutGlobalScopes()->count());
        $this->assertSame(9, Reply::query()->withoutGlobalScopes()->where('status', Reply::STATUS_COMPLETED)->count());
    }

    public function test_the_report_counts_the_answer_calls_and_their_tokens(): void
    {
        Http::fake(['*' => fn () => Http::response($this->streamedAnswer('Seven days.', completionTokens: 20))]);
        $user = $this->userInTenant();

        $this->artisan('conversation:replay', ['script' => $this->script, '--user' => $user->id, '--users' => 2])
            ->expectsOutputToContain('TTFT from 6 calls')
            ->assertSuccessful();

        $this->assertSame(6, ModelCall::query()->where('feature', 'support.reply')->count());
    }

    public function test_it_warns_that_a_sync_queue_serves_the_users_one_after_another(): void
    {
        Http::fake(['*' => fn () => Http::response($this->streamedAnswer('Ok.'))]);
        $user = $this->userInTenant();

        $this->artisan('conversation:replay', ['script' => $this->script, '--user' => $user->id, '--users' => 1])
            ->expectsOutputToContain('The queue connection is sync')
            ->assertSuccessful();
    }

    public function test_a_failing_model_marks_the_users_failed_and_exits_non_zero(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'engine dead']], 400)]);
        $user = $this->userInTenant();

        $this->artisan('conversation:replay', ['script' => $this->script, '--user' => $user->id, '--users' => 2])
            ->expectsOutputToContain('2 failures')
            ->expectsOutputToContain('0 users finished, 2 failed')
            ->assertFailed();
    }

    public function test_with_a_real_queue_the_users_wait_for_their_replies_and_then_send_the_next_turn(): void
    {
        Queue::fake();
        Sleep::whenFakingSleep(fn () => $this->finishPendingReplies(Reply::STATUS_COMPLETED));
        $user = $this->userInTenant();

        $this->artisan('conversation:replay', ['script' => $this->script, '--user' => $user->id, '--users' => 2])
            ->expectsOutputToContain('2 users finished, 0 failed, 0 waiting for an approval, 0 timed out')
            ->assertSuccessful();

        $this->assertSame(6, Reply::query()->withoutGlobalScopes()->count());
    }

    public function test_a_reply_that_pauses_for_an_approval_stops_that_user_and_is_not_a_success(): void
    {
        Queue::fake();
        Sleep::whenFakingSleep(fn () => $this->finishPendingReplies(Reply::STATUS_PAUSED));
        $user = $this->userInTenant();

        $this->artisan('conversation:replay', ['script' => $this->script, '--user' => $user->id, '--users' => 2])
            ->expectsOutputToContain('0 users finished, 0 failed, 2 waiting for an approval')
            ->assertFailed();
    }

    public function test_replies_that_never_finish_time_the_users_out(): void
    {
        Queue::fake();
        $user = $this->userInTenant();

        $this->artisan('conversation:replay', ['script' => $this->script, '--user' => $user->id, '--users' => 2, '--timeout' => 0])
            ->expectsOutputToContain('0 users finished, 0 failed, 0 waiting for an approval, 2 timed out')
            ->assertFailed();
    }

    public function test_a_failed_reply_stops_that_user_only(): void
    {
        Queue::fake();
        $sleeps = 0;
        Sleep::whenFakingSleep(function () use (&$sleeps): void {
            $sleeps++;

            $this->finishPendingReplies(
                status: $sleeps === 1
                    ? Reply::STATUS_FAILED
                    : Reply::STATUS_COMPLETED,
                onlyTheFirst: $sleeps === 1,
            );
        });
        $user = $this->userInTenant();

        $this->artisan('conversation:replay', ['script' => $this->script, '--user' => $user->id, '--users' => 2])
            ->expectsOutputToContain('1 users finished, 1 failed')
            ->assertFailed();
    }

    public function test_a_user_count_below_one_is_refused(): void
    {
        $user = $this->userInTenant();

        $this->artisan('conversation:replay', ['script' => $this->script, '--user' => $user->id, '--users' => 0])
            ->expectsOutputToContain('--users must be a whole number of at least 1')
            ->assertFailed();
    }

    public function test_an_unreadable_script_is_reported(): void
    {
        $user = $this->userInTenant();

        $this->artisan('conversation:replay', ['script' => '/nonexistent/script.json', '--user' => $user->id, '--users' => 2])
            ->expectsOutputToContain('does not exist')
            ->assertFailed();
    }

    public function test_without_users_the_replay_is_the_single_user_synchronous_one(): void
    {
        Http::fake(['*' => Http::response($this->completion('ok'))]);
        $user = $this->userInTenant();

        $this->artisan('conversation:replay', ['script' => $this->script, '--user' => $user->id])
            ->expectsOutputToContain('turn 1 prompt')
            ->assertSuccessful();

        $this->assertSame(0, Reply::query()->withoutGlobalScopes()->count());
    }
}
