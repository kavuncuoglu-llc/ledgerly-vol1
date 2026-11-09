<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'run_id',
    'round',
    'position',
    'conversation_id',
    'tool',
    'tool_call_id',
    'arguments',
    'summary',
    'status',
    'result',
    'source',
    'token_name',
    'messages',
    'options',
    'feature',
    'prompt_version',
    'resolved_at',
])]
class PendingToolCall extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_EXPIRED = 'expired';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'round' => 'integer',
            'position' => 'integer',
            'conversation_id' => 'integer',
            'arguments' => 'array',
            'result' => 'array',
            'messages' => 'array',
            'options' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
