<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class ConversationTenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(CurrentTenant::class)->id();

        $builder->whereIn(
            $model->qualifyColumn('conversation_id'),
            fn (QueryBuilder $query) => $this->tenantConversations($query, $tenantId),
        );
    }

    private function tenantConversations(QueryBuilder $query, ?int $tenantId): void
    {
        $query
            ->select('conversations.id')
            ->from('conversations');

        if ($tenantId === null) {
            $query->whereNull('conversations.tenant_id');

            return;
        }

        $query->where('conversations.tenant_id', $tenantId);
    }
}
