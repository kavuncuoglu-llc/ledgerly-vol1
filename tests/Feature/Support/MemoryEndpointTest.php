<?php

namespace Tests\Feature\Support;

use App\Models\User;
use App\Models\UserMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsConversations;
use Tests\TestCase;

class MemoryEndpointTest extends TestCase
{
    use BuildsConversations;
    use RefreshDatabase;

    public function test_index_lists_only_the_users_own_facts_with_their_source(): void
    {
        $user = $this->userInTenant();
        $colleague = User::factory()->forCustomer($user->customer)->create();
        UserMemory::factory()->forUser($user)->create(['key' => 'role', 'value' => 'bookkeeper', 'source' => 'stated']);
        UserMemory::factory()->forUser($colleague)->create(['key' => 'role', 'value' => 'owner']);

        $response = $this->actingAs($user)->getJson('support/memories');

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.key', 'role');
        $response->assertJsonPath('data.0.value', 'bookkeeper');
        $response->assertJsonPath('data.0.source', 'stated');
    }

    public function test_index_rejects_a_guest_with_401(): void
    {
        $this->getJson('support/memories')->assertUnauthorized();
    }

    public function test_destroy_deletes_the_fact(): void
    {
        $user = $this->userInTenant();
        $memory = UserMemory::factory()->forUser($user)->create();

        $this->actingAs($user)->deleteJson("support/memories/{$memory->id}")->assertNoContent();

        $this->assertDatabaseMissing('user_memories', ['id' => $memory->id]);
    }

    public function test_destroy_rejects_a_guest_with_401(): void
    {
        $memory = UserMemory::factory()->forUser($this->userInTenant())->create();

        $this->deleteJson("support/memories/{$memory->id}")->assertUnauthorized();
    }

    public function test_destroy_rejects_a_colleagues_fact_with_403(): void
    {
        $user = $this->userInTenant();
        $colleague = User::factory()->forCustomer($user->customer)->create();
        $memory = UserMemory::factory()->forUser($colleague)->create();

        $this->actingAs($user)->deleteJson("support/memories/{$memory->id}")->assertForbidden();

        $this->assertDatabaseHas('user_memories', ['id' => $memory->id]);
    }

    public function test_destroy_answers_404_for_a_fact_in_another_tenant(): void
    {
        $memory = UserMemory::factory()->forUser($this->userInTenant())->create();

        $this->actingAs($this->userInTenant())->deleteJson("support/memories/{$memory->id}")->assertNotFound();
    }
}
