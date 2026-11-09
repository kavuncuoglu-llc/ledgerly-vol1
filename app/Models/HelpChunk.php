<?php

namespace App\Models;

use App\Casts\Vector;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'source',
    'position',
    'heading',
    'content',
    'tokens',
    'embedding',
])]
class HelpChunk extends Model
{
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'tokens' => 'integer',
            'embedding' => Vector::class,
        ];
    }

    public function scopeNearestTo(Builder $query, array $vector, int $limit = 5): Builder
    {
        $literal = Vector::toLiteral($vector);

        return $query
            ->select([
                'id',
                'source',
                'position',
                'heading',
                'content',
                'tokens',
            ])
            ->selectRaw('1 - (embedding <=> ?::vector) as similarity', [$literal])
            ->orderByRaw('embedding <=> ?::vector', [$literal])
            ->limit($limit);
    }
}
