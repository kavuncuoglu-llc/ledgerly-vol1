<?php

namespace App\Http\Requests;

use App\Ai\Conversations\ConversationStore;
use App\Models\Conversation;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'question' => [
                'required',
                'string',
                'max:2000',
            ],
            'request_id' => [
                'nullable',
                'uuid',
            ],
            'conversation_id' => [
                'nullable',
                'integer',
                Rule::exists('conversations', 'id')->where(fn (Builder $query) => $this->ownOpenConversation($query)),
            ],
        ];
    }

    public function conversation(): Conversation
    {
        $id = $this->validated('conversation_id');

        if ($id === null) {
            return new Conversation;
        }

        return app(ConversationStore::class)->find((int) $id);
    }

    private function ownOpenConversation(Builder $query): Builder
    {
        $query
            ->where('user_id', $this->user()->id)
            ->where('status', Conversation::STATUS_OPEN);

        $tenantId = app(CurrentTenant::class)->id();

        if ($tenantId === null) {
            return $query->whereNull('tenant_id');
        }

        return $query->where('tenant_id', $tenantId);
    }
}
