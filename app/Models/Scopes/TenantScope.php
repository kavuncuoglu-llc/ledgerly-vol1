<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $column = $model->qualifyColumn('tenant_id');

        $tenantId = app(CurrentTenant::class)->id();

        if ($tenantId === null) {
            $builder->whereNull($column);

            return;
        }

        $builder->where($column, $tenantId);
    }
}
