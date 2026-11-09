<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'user_id',
    'conversation_id',
    'model_call_id',
    'tool',
    'tool_call_id',
    'arguments',
    'decision',
    'result',
    'duration_ms',
    'source',
    'token_name',
])]
class ToolCall extends Model
{
    public const DECISION_ALLOWED = 'allowed';

    public const DECISION_DENIED = 'denied';

    public const DECISION_APPROVED = 'approved';

    public const DECISION_DECLINED = 'declined';

    public const DECISION_PENDING_APPROVAL = 'pending_approval';

    public const DECISION_INVALID = 'invalid';

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'conversation_id' => 'integer',
            'model_call_id' => 'integer',
            'arguments' => 'array',
            'result' => 'array',
            'duration_ms' => 'integer',
        ];
    }
}
