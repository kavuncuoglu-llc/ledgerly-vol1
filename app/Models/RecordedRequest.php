<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'model_call_id',
    'feature',
    'provider',
    'conversation_id',
    'request',
])]
class RecordedRequest extends Model
{
    protected function casts(): array
    {
        return [
            'model_call_id' => 'integer',
            'conversation_id' => 'integer',
            'request' => 'array',
        ];
    }
}
