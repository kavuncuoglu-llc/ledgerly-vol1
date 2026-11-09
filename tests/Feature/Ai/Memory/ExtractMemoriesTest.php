<?php

namespace Tests\Feature\Ai\Memory;

use App\Ai\Memory\ExtractMemories;
use App\Models\ModelCall;
use App\Models\UserMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsConversations;
use Tests\TestCase;

class ExtractMemoriesTest extends TestCase
{
    use BuildsConversations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_it_stores_facts_the_customer_stated_as_extracted_memories(): void
    {
        $user = $this->userInTenant();
        $conversation = $this->conversationFor($user);
        $this->addMessage($conversation, 'user', "I'm the bookkeeper at Acme Studios, not the owner. Please use billing@acme.example.");
        $this->addMessage($conversation, 'assistant', 'Noted.');
        Http::fake(['*' => Http::response($this->completion($this->facts([
            ['role', 'bookkeeper for Acme Studios, not the owner', 0.95],
            ['billing_contact', 'billing@acme.example', 0.9],
        ])))]);

        ExtractMemories::dispatchSync($conversation);

        $memories = UserMemory::query()->withoutGlobalScopes()->orderBy('key')->get();
        $this->assertSame(['billing_contact', 'role'], $memories->pluck('key')->all());
        $this->assertSame('billing@acme.example', $memories->first()->value);
        $this->assertSame('extracted', $memories->first()->source);
        $this->assertSame($conversation->id, $memories->first()->conversation_id);
        $this->assertSame($user->customer_id, $memories->first()->tenant_id);
    }

    public function test_it_discards_low_confidence_facts(): void
    {
        $conversation = $this->conversationFor($this->userInTenant());
        $this->addMessage($conversation, 'user', 'I think my timezone is New York maybe');
        Http::fake(['*' => Http::response($this->completion($this->facts([
            ['timezone', 'New York', 0.4],
        ])))]);

        ExtractMemories::dispatchSync($conversation);

        $this->assertSame(0, UserMemory::query()->withoutGlobalScopes()->count());
    }

    public function test_it_never_stores_a_fact_the_customer_did_not_state(): void
    {
        $conversation = $this->conversationFor($this->userInTenant());
        $this->addMessage($conversation, 'user', 'Why is INV-4471 overdue?');
        $this->addMessage($conversation, 'assistant', 'It sounds like you are the owner and you seem frustrated about cash flow.');
        Http::fake(['*' => Http::response($this->completion($this->facts([
            ['role', 'owner of the business', 0.9],
            ['mood', 'frustrated about cash flow', 0.9],
        ])))]);

        ExtractMemories::dispatchSync($conversation);

        $this->assertSame(0, UserMemory::query()->withoutGlobalScopes()->count());
    }

    public function test_it_rejects_keys_that_are_not_snake_case_names(): void
    {
        $conversation = $this->conversationFor($this->userInTenant());
        $this->addMessage($conversation, 'user', 'My role is bookkeeper');
        Http::fake(['*' => Http::response($this->completion($this->facts([
            ['My Role!', 'bookkeeper', 0.9],
        ])))]);

        ExtractMemories::dispatchSync($conversation);

        $this->assertSame(0, UserMemory::query()->withoutGlobalScopes()->count());
    }

    public function test_a_newer_value_overwrites_the_same_key_instead_of_duplicating_it(): void
    {
        $user = $this->userInTenant();
        $conversation = $this->conversationFor($user);
        $this->addMessage($conversation, 'user', 'Actually I am the owner of the business now');
        UserMemory::factory()->forUser($user)->create(['key' => 'role', 'value' => 'bookkeeper']);
        Http::fake(['*' => Http::response($this->completion($this->facts([
            ['role', 'owner of the business', 0.9],
        ])))]);

        ExtractMemories::dispatchSync($conversation);

        $memory = UserMemory::query()->withoutGlobalScopes()->sole();
        $this->assertSame('owner of the business', $memory->value);
        $this->assertSame('extracted', $memory->source);
    }

    public function test_it_sends_the_conversation_to_the_model_and_logs_the_call_against_it(): void
    {
        $conversation = $this->conversationFor($this->userInTenant());
        $this->addMessage($conversation, 'user', 'I am the bookkeeper');
        $this->addMessage($conversation, 'assistant', 'Thanks');
        Http::fake(['*' => Http::response($this->completion($this->facts([])))]);

        ExtractMemories::dispatchSync($conversation);

        $request = Http::recorded()[0][0];
        $this->assertStringContainsString('ABOUT THE CUSTOMER', $request['messages'][0]['content']);
        $this->assertStringContainsString("customer: I am the bookkeeper\nassistant: Thanks", $request['messages'][1]['content']);
        $this->assertSame('json_schema', $request['response_format']['type']);
        $call = ModelCall::query()->sole();
        $this->assertSame('memory.extract', $call->feature);
        $this->assertSame($conversation->id, $call->conversation_id);
    }

    public function test_it_does_not_call_the_model_for_a_conversation_without_customer_turns(): void
    {
        Http::fake();
        $conversation = $this->conversationFor($this->userInTenant());

        ExtractMemories::dispatchSync($conversation);

        Http::assertNothingSent();
    }

    private function facts(array $rows): string
    {
        return json_encode([
            'facts' => array_map(
                fn (array $row): array => ['key' => $row[0], 'value' => $row[1], 'confidence' => $row[2]],
                $rows,
            ),
        ]);
    }
}
