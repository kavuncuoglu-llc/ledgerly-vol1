<?php

namespace Tests\Feature\Ai\Memory;

use App\Ai\Memory\MemoryStore;
use App\Models\UserMemory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsConversations;
use Tests\TestCase;

class MemoryStoreTest extends TestCase
{
    use BuildsConversations;
    use RefreshDatabase;

    public function test_block_is_empty_when_the_user_has_no_facts(): void
    {
        $user = $this->userInTenant();
        $this->actingAs($user);

        $this->assertSame('', app(MemoryStore::class)->block($user));
    }

    public function test_block_lists_facts_under_the_heading_sorted_by_key(): void
    {
        $user = $this->userInTenant();
        $this->actingAs($user);
        UserMemory::factory()->forUser($user)->create(['key' => 'timezone', 'value' => 'America/New_York']);
        UserMemory::factory()->forUser($user)->create(['key' => 'billing_contact', 'value' => 'billing@acme.example']);
        UserMemory::factory()->forUser($user)->create(['key' => 'role', 'value' => 'bookkeeper for Acme Studios, not the owner']);

        $block = app(MemoryStore::class)->block($user);

        $this->assertSame(
            "What you know about this customer (they can edit this in Settings):\n- billing_contact: billing@acme.example\n- role: bookkeeper for Acme Studios, not the owner\n- timezone: America/New_York",
            $block,
        );
    }

    public function test_block_carries_at_most_twelve_facts(): void
    {
        $user = $this->userInTenant();
        $this->actingAs($user);
        UserMemory::factory()->count(15)->forUser($user)->create();

        $lines = explode("\n", app(MemoryStore::class)->block($user));

        $this->assertCount(13, $lines);
    }

    public function test_block_never_includes_another_users_facts(): void
    {
        $user = $this->userInTenant();
        $other = $this->userInTenant($user->customer);
        $this->actingAs($user);
        UserMemory::factory()->forUser($other)->create(['key' => 'role', 'value' => 'secret-other']);

        $this->assertSame('', app(MemoryStore::class)->block($user));
    }

    public function test_create_then_find_returns_the_stored_fact(): void
    {
        $user = $this->userInTenant();
        $this->actingAs($user);
        $store = app(MemoryStore::class);

        $store->create($user, 'role', 'bookkeeper', UserMemory::SOURCE_STATED);

        $this->assertSame('bookkeeper', $store->find($user, 'role')->value);
        $this->assertFalse($store->find($user, 'missing')->exists);
    }

    public function test_the_unique_index_stops_a_second_fact_with_the_same_key(): void
    {
        $user = $this->userInTenant();
        $this->actingAs($user);
        $store = app(MemoryStore::class);
        $store->create($user, 'role', 'bookkeeper', UserMemory::SOURCE_STATED);

        $this->expectException(UniqueConstraintViolationException::class);

        $store->create($user, 'role', 'owner', UserMemory::SOURCE_STATED);
    }
}
