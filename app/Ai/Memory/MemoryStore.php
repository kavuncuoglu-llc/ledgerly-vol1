<?php

namespace App\Ai\Memory;

use App\Models\User;
use App\Models\UserMemory;
use Illuminate\Support\Collection;

final class MemoryStore
{
    public const BLOCK_HEADING = 'What you know about this customer (they can edit this in Settings):';

    public function forUser(User $user): Collection
    {
        return UserMemory::query()
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->limit((int) config('ai.memory.max_facts'))
            ->get()
            ->sortBy('key')
            ->values();
    }

    public function all(User $user): Collection
    {
        return UserMemory::query()
            ->where('user_id', $user->id)
            ->orderBy('key')
            ->get();
    }

    public function block(User $user): string
    {
        $facts = $this->forUser($user);

        if ($facts->isEmpty()) {
            return '';
        }

        $lines = $facts->map(fn (UserMemory $fact): string => "- {$fact->key}: {$fact->value}");

        return self::BLOCK_HEADING."\n".$lines->implode("\n");
    }

    public function find(User $user, string $key): UserMemory
    {
        return UserMemory::query()
            ->where('user_id', $user->id)
            ->where('key', $key)
            ->first() ?? new UserMemory;
    }

    public function create(User $user, string $key, string $value, string $source, ?int $conversationId = null): UserMemory
    {
        return UserMemory::create([
            'user_id' => $user->id,
            'tenant_id' => $user->customer_id,
            'key' => $key,
            'value' => $value,
            'source' => $source,
            'conversation_id' => $conversationId,
        ]);
    }

    public function update(UserMemory $memory, string $value, string $source, ?int $conversationId = null): UserMemory
    {
        $memory->update([
            'value' => $value,
            'source' => $source,
            'conversation_id' => $conversationId,
        ]);

        return $memory;
    }

    public function markUsed(User $user): void
    {
        UserMemory::query()
            ->whereKey($this->forUser($user)->modelKeys())
            ->update([
                'last_used_at' => now(),
            ]);
    }

    public function forget(UserMemory $memory): void
    {
        $memory->delete();
    }

    public function expireUnused(): int
    {
        $cutoff = now()->subDays((int) config('ai.memory.expire_after_days'));

        return UserMemory::query()
            ->withoutGlobalScopes()
            ->where(fn ($query) => $query
                ->where('last_used_at', '<', $cutoff)
                ->orWhere(fn ($unused) => $unused
                    ->whereNull('last_used_at')
                    ->where('created_at', '<', $cutoff)))
            ->delete();
    }
}
