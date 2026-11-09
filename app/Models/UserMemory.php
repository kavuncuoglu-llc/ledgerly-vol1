<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Database\Factories\UserMemoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'tenant_id',
    'key',
    'value',
    'source',
    'conversation_id',
    'message_id',
    'last_used_at',
])]
class UserMemory extends Model
{
    use HasFactory;

    public const SOURCE_STATED = 'stated';

    public const SOURCE_EXTRACTED = 'extracted';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected static function newFactory(): UserMemoryFactory
    {
        return UserMemoryFactory::new();
    }

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'tenant_id' => 'integer',
            'conversation_id' => 'integer',
            'message_id' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
