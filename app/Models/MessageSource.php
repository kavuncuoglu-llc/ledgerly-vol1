<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'message_id',
    'help_chunk_id',
    'label',
    'source',
    'heading_path',
    'help_version',
])]
class MessageSource extends Model
{
    protected function casts(): array
    {
        return [
            'message_id' => 'integer',
            'help_chunk_id' => 'integer',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
