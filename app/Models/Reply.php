<?php

namespace App\Models;

use App\Models\Scopes\ConversationTenantScope;
use Carbon\CarbonInterface;
use Database\Factories\ReplyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'conversation_id',
    'user_id',
    'request_key',
    'question',
    'status',
    'content',
    'tokens',
    'sources',
    'citations_ok',
    'paused_for',
    'resume_token',
    'paused_at',
    'error',
    'feedback',
    'completed_at',
])]
class Reply extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_STREAMING = 'streaming';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_RESUMING = 'resuming';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const FEEDBACK_UP = 'up';

    public const FEEDBACK_DOWN = 'down';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'tokens' => 0,
        'citations_ok' => true,
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new ConversationTenantScope);
    }

    protected static function newFactory(): ReplyFactory
    {
        return ReplyFactory::new();
    }

    protected function casts(): array
    {
        return [
            'conversation_id' => 'integer',
            'user_id' => 'integer',
            'tokens' => 'integer',
            'sources' => 'array',
            'citations_ok' => 'boolean',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePausedBefore(Builder $query, CarbonInterface $moment): Builder
    {
        return $query
            ->where('status', self::STATUS_PAUSED)
            ->where('paused_at', '<', $moment);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_EXPIRED,
        ], true);
    }

    public function isPaused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }

    public function channelName(): string
    {
        return "reply.{$this->id}";
    }
}
