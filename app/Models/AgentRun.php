<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'agent',
    'user_id',
    'triage_run_id',
    'status',
    'reason',
    'goal',
    'state',
    'budget',
    'trace',
    'rounds',
    'tokens',
    'writes',
    'duration_ms',
])]
class AgentRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_EXHAUSTED = 'exhausted';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'state' => 'array',
            'budget' => 'array',
            'trace' => 'array',
            'rounds' => 'integer',
            'tokens' => 'integer',
            'writes' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
