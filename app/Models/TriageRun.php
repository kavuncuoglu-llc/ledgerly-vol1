<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'event_id',
    'ticket_id',
    'status',
    'category',
    'agreement',
    'votes',
    'reason',
    'fields',
    'urgent',
    'assignee',
    'findings',
    'draft',
    'agent_run_id',
    'error',
    'duration_ms',
    'reviewed_at',
])]
class TriageRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_QUEUED_FOR_REVIEW = 'queued_for_review';

    public const STATUS_HUMAN = 'human';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'agreement' => 'float',
            'votes' => 'array',
            'fields' => 'array',
            'urgent' => 'boolean',
            'findings' => 'array',
            'duration_ms' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_QUEUED_FOR_REVIEW)
            ->whereNull('reviewed_at');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }
}
