<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'feature',
    'provider',
    'model',
    'prompt_tokens',
    'completion_tokens',
    'finish_reason',
    'latency_ms',
    'cost_micros',
    'conversation_id',
    'prompt_version',
    'repaired',
])]
class ModelCall extends Model
{
    protected function casts(): array
    {
        return [
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'latency_ms' => 'integer',
            'cost_micros' => 'integer',
            'conversation_id' => 'integer',
            'repaired' => 'boolean',
        ];
    }
}
