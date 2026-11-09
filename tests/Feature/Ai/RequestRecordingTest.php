<?php

namespace Tests\Feature\Ai;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Models\RecordedRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsConversations;
use Tests\TestCase;

class RequestRecordingTest extends TestCase
{
    use BuildsConversations;
    use RefreshDatabase;

    public function test_nothing_is_recorded_by_default(): void
    {
        $this->callModel();

        $this->assertSame(0, RecordedRequest::query()->count());
    }

    public function test_with_the_switch_on_each_call_stores_its_normalized_request_next_to_its_model_call(): void
    {
        config()->set('ai.debug.record_requests', true);

        $this->callModel();

        $row = RecordedRequest::query()->sole();
        $this->assertSame('support.reply', $row->feature);
        $this->assertSame('local', $row->provider);
        $this->assertNotNull($row->model_call_id);
        $this->assertSame('Hi', $row->request['messages'][0]['content']);
        $this->assertSame(['max_tokens' => 50], $row->request['options']);
    }

    public function test_a_recorded_request_rebuilds_into_the_same_request(): void
    {
        $request = ChatRequest::fromOptions([['role' => 'user', 'content' => 'Hi']], ['max_tokens' => 50, 'profile' => 'assistant', 'tool_choice' => 'auto'], 'support.reply', promptVersion: 'v1');

        $rebuilt = ChatRequest::fromRecorded($request->toRecorded());

        $this->assertEquals($request->messages, $rebuilt->messages);
        $this->assertSame('assistant', $rebuilt->profile);
        $this->assertSame('auto', $rebuilt->toolChoice);
        $this->assertSame('v1', $rebuilt->promptVersion);
        $this->assertSame(['max_tokens' => 50], $rebuilt->options);
    }

    public function test_retention_deletes_old_recorded_requests_and_keeps_recent_ones(): void
    {
        $old = $this->row();
        $old->forceFill(['created_at' => now()->subDays(8)])->save();
        $recent = $this->row();

        $this->artisan('conversations:retain')->expectsOutputToContain('Deleted 1 recorded requests')->assertSuccessful();

        $this->assertSame([$recent->id], RecordedRequest::query()->pluck('id')->all());
    }

    public function test_forgetting_a_user_deletes_the_requests_recorded_in_their_conversations(): void
    {
        $user = $this->userInTenant();
        $conversation = $this->conversationFor($user);
        $this->row($conversation->id);
        $kept = $this->row(null);

        $this->artisan('user:forget', ['user' => $user->id, '--confirm' => true])->expectsOutputToContain('1 recorded requests')->assertSuccessful();

        $this->assertSame([$kept->id], RecordedRequest::query()->pluck('id')->all());
    }

    private function row(?int $conversationId = null): RecordedRequest
    {
        return RecordedRequest::create([
            'feature' => 'support.reply',
            'provider' => 'local',
            'conversation_id' => $conversationId,
            'request' => ['feature' => 'support.reply', 'messages' => []],
        ]);
    }

    private function callModel(): void
    {
        Http::fake(['*' => Http::response(['id' => 'x', 'model' => 'llama3.1:8b', 'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hello'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1]])]);

        app(AiManager::class)->chat('local')->chat(ChatRequest::fromOptions([['role' => 'user', 'content' => 'Hi']], ['max_tokens' => 50, 'profile' => null], 'support.reply'));
    }
}
