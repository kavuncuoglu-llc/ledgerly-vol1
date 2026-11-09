<?php

namespace App\Models;

use App\Models\Scopes\ConversationTenantScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'conversation_id',
    'seq',
    'role',
    'content',
    'tool_calls',
    'tool_call_id',
    'tokens',
    'model_call_id',
])]
class Message extends Model
{
    public const ROLE_SYSTEM = 'system';

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_TOOL = 'tool';

    protected static function booted(): void
    {
        static::addGlobalScope(new ConversationTenantScope);
    }

    protected function casts(): array
    {
        return [
            'conversation_id' => 'integer',
            'seq' => 'integer',
            'tool_calls' => 'array',
            'tokens' => 'integer',
            'model_call_id' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function toApiMessage(): array
    {
        $message = [
            'role' => $this->role,
            'content' => $this->content,
        ];

        if ($this->tool_calls !== null) {
            $message['tool_calls'] = $this->tool_calls;
        }

        if ($this->tool_call_id !== null) {
            $message['tool_call_id'] = $this->tool_call_id;
        }

        return $message;
    }
}
