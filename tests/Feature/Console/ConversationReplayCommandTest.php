<?php

namespace Tests\Feature\Console;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\ModelCall;
use App\Models\User;
use App\Models\UserMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsConversations;
use Tests\TestCase;

class ConversationReplayCommandTest extends TestCase
{
    use BuildsConversations;
    use RefreshDatabase;

    private string $script;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        $this->script = tempnam(sys_get_temp_dir(), 'replay');
    }

    protected function tearDown(): void
    {
        unlink($this->script);

        parent::tearDown();
    }

    public function test_it_replays_every_message_into_one_conversation_and_prints_a_line_per_turn(): void
    {
        $this->writeScript(['one', 'two', 'three'], 'Going back to the first one?');
        Http::fake(['*' => Http::response($this->completion('ok', promptTokens: 1234))]);
        $user = $this->userInTenant();

        $this->artisan('conversation:replay', ['script' => $this->script, '--user' => $user->id])
            ->expectsOutputToContain('turn 1 prompt 1,234 summary: no')
            ->expectsOutputToContain('turn 3 prompt 1,234 summary: no')
            ->expectsOutputToContain('A: ok')
            ->assertSuccessful();

        $this->assertSame(1, Conversation::query()->withoutGlobalScopes()->count());
        $this->assertSame(8, Message::query()->withoutGlobalScopes()->count());
    }

    public function test_it_runs_the_summary_job_inline_once_the_allowance_is_exceeded(): void
    {
        config()->set('ai.conversation.summary_trigger_tokens', 1);
        $this->writeScript(['a', 'b', 'c', 'd', 'e', 'f'], '');
        Http::fake(['*' => Http::response($this->completion('Problem: INV-4471'))]);
        $user = $this->userInTenant();

        $this->artisan('conversation:replay', ['script' => $this->script, '--user' => $user->id])
            ->expectsOutputToContain('summary: yes (through seq')
            ->assertSuccessful();

        $this->assertNotNull(Conversation::query()->withoutGlobalScopes()->sole()->summary);
        $this->assertGreaterThan(0, ModelCall::query()->where('feature', 'conversation.summarize')->count());
    }

    public function test_it_finds_the_user_by_email(): void
    {
        $this->writeScript(['one'], '');
        Http::fake(['*' => Http::response($this->completion('ok'))]);
        $user = User::factory()->forCustomer(Customer::factory()->create())->create(['email' => 'acme@example.com']);

        $this->artisan('conversation:replay', ['script' => $this->script])->assertSuccessful();

        $this->assertSame($user->id, Conversation::query()->withoutGlobalScopes()->sole()->user_id);
    }

    public function test_it_closes_the_conversation_and_prints_extracted_memories_with_close(): void
    {
        $this->writeScript(['I am the bookkeeper at Acme Studios'], '');
        Http::fake(['*' => Http::sequence()
            ->push($this->completion('ok'))
            ->push($this->completion(json_encode(['facts' => [['key' => 'role', 'value' => 'bookkeeper at Acme Studios', 'confidence' => 0.9]]]))),
        ]);
        $user = $this->userInTenant();

        $this->artisan('conversation:replay', ['script' => $this->script, '--user' => $user->id, '--close' => true])
            ->expectsOutputToContain('- role: bookkeeper at Acme Studios')
            ->assertSuccessful();

        $this->assertSame(Conversation::STATUS_CLOSED, Conversation::query()->withoutGlobalScopes()->sole()->status);
        $this->assertSame(1, UserMemory::query()->withoutGlobalScopes()->count());
    }

    public function test_it_fails_for_an_unknown_user(): void
    {
        $this->writeScript(['one'], '');

        $this->artisan('conversation:replay', ['script' => $this->script, '--user' => 999999])
            ->expectsOutputToContain('No such user.')
            ->assertFailed();
    }

    public function test_it_fails_for_a_missing_script(): void
    {
        $user = $this->userInTenant();

        $this->artisan('conversation:replay', ['script' => '/nonexistent.json', '--user' => $user->id])
            ->expectsOutputToContain('does not exist')
            ->assertFailed();
    }

    public function test_it_reports_an_unreachable_model_without_a_stack_trace(): void
    {
        $this->writeScript(['one'], '');
        Http::fake(['*' => Http::response('down', 500)]);
        $user = $this->userInTenant();

        $this->artisan('conversation:replay', ['script' => $this->script, '--user' => $user->id])
            ->expectsOutputToContain('HTTP 500')
            ->assertFailed();
    }

    private function writeScript(array $messages, string $followUp): void
    {
        file_put_contents($this->script, json_encode([
            'messages' => $messages,
            'followUp' => $followUp,
        ]));
    }
}
