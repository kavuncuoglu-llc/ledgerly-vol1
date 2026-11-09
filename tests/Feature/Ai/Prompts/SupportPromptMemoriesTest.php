<?php

namespace Tests\Feature\Ai\Prompts;

use App\Ai\Prompts\SupportPrompt;
use App\Models\UserMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsConversations;
use Tests\TestCase;

class SupportPromptMemoriesTest extends TestCase
{
    use BuildsConversations;
    use RefreshDatabase;

    public function test_system_is_the_base_text_when_the_user_has_no_memories(): void
    {
        $user = $this->userInTenant();
        $this->actingAs($user);

        $this->assertSame(SupportPrompt::system(), SupportPrompt::system($user));
    }

    public function test_system_appends_the_memories_block_after_the_base_text(): void
    {
        $user = $this->userInTenant();
        $this->actingAs($user);
        UserMemory::factory()->forUser($user)->create(['key' => 'role', 'value' => 'bookkeeper for Acme Studios, not the owner']);
        UserMemory::factory()->forUser($user)->create(['key' => 'billing_contact', 'value' => 'billing@acme.example']);

        $system = SupportPrompt::system($user);

        $this->assertStringStartsWith(SupportPrompt::system(), $system);
        $this->assertStringEndsWith(
            "\n\nWhat you know about this customer (they can edit this in Settings):\n- billing_contact: billing@acme.example\n- role: bookkeeper for Acme Studios, not the owner",
            $system,
        );
    }

    public function test_messages_put_the_memories_in_the_system_message_not_in_a_separate_turn(): void
    {
        $user = $this->userInTenant();
        $this->actingAs($user);
        UserMemory::factory()->forUser($user)->create(['key' => 'timezone', 'value' => 'America/New_York']);

        $messages = SupportPrompt::messages(question: 'Hi', user: $user);

        $this->assertSame(['system', 'user'], array_column($messages, 'role'));
        $this->assertStringContainsString('- timezone: America/New_York', $messages[0]['content']);
    }
}
