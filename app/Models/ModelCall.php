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
    'cached_tokens',
    'cached_tokens_estimated',
    'ttft_ms',
    'context_breakdown',
    'semantic_cache_hit',
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
            'cached_tokens' => 'integer',
            'cached_tokens_estimated' => 'boolean',
            'ttft_ms' => 'integer',
            'context_breakdown' => 'array',
            'semantic_cache_hit' => 'boolean',
        ];
    }
}
