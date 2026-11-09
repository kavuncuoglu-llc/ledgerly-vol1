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
    'title',
    'heading_path',
    'tags',
    'help_version',
    'source_modified_at',
    'chunk_key',
    'parent_id',
    'kind',
    'context_header',
])]
class HelpChunk extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $chunk): void {
            $chunk->chunk_key ??= sha1($chunk->source.$chunk->heading_path.$chunk->position);
        });
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'tokens' => 'integer',
            'embedding' => Vector::class,
            'tags' => 'array',
            'source_modified_at' => 'datetime',
        ];
    }

    public function scopeSearchable(Builder $query): Builder
    {
        return $query->whereNotNull('embedding');
    }

    public function scopeNearestTo(Builder $query, array $vector, int $limit = 5): Builder
    {
        $literal = Vector::toLiteral($vector);

        return $query
            ->searchable()
            ->select([
                'id',
                'source',
                'position',
                'heading',
                'content',
                'tokens',
            ])
            ->selectRaw('1 - (embedding::halfvec(768) <=> ?::halfvec(768)) as similarity', [$literal])
            ->orderByRaw('embedding::halfvec(768) <=> ?::halfvec(768)', [$literal])
            ->limit($limit);
    }
}
