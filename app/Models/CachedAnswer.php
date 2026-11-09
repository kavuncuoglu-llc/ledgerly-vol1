<?php

namespace App\Models;

use App\Casts\Vector;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'tenant_id',
    'category',
    'question',
    'answer',
    'help_version',
    'status',
    'hit_count',
    'expires_at',
    'embedding',
])]
class CachedAnswer extends Model
{
    public const STATUS_AUTO = 'auto';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $attributes = [
        'status' => self::STATUS_AUTO,
        'hit_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'hit_count' => 'integer',
            'expires_at' => 'datetime',
            'embedding' => Vector::class,
        ];
    }

    public function scopeVisibleTo(Builder $query, int $tenantId): Builder
    {
        return $query
            ->whereIn('status', [self::STATUS_AUTO, self::STATUS_APPROVED])
            ->where(function (Builder $scope) use ($tenantId) {
                $scope
                    ->where('tenant_id', $tenantId)
                    ->orWhere(function (Builder $shared) {
                        $shared
                            ->whereNull('tenant_id')
                            ->where('status', self::STATUS_APPROVED);
                    });
            });
    }

    public function scopeUnexpired(Builder $query): Builder
    {
        return $query->where(function (Builder $scope) {
            $scope
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    public function scopeNearestTo(Builder $query, array $vector): Builder
    {
        $literal = Vector::toLiteral($vector);

        return $query
            ->select([
                'id',
                'tenant_id',
                'category',
                'question',
                'answer',
                'help_version',
                'status',
                'hit_count',
                'expires_at',
            ])
            ->selectRaw('1 - (embedding <=> ?::vector) as similarity', [$literal])
            ->orderByRaw('embedding <=> ?::vector', [$literal]);
    }
}
