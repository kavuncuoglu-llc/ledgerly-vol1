<?php

namespace App\Ai\Listeners;

use App\Ai\Events\ToolCalled;
use App\Models\ToolCall;

final class RecordToolCall
{
    public function handle(ToolCalled $event): void
    {
        ToolCall::create([
            'user_id' => $event->ctx->user->id,
            'conversation_id' => $event->ctx->conversationId,
            'model_call_id' => $event->ctx->modelCallId,
            'tool' => $event->tool,
            'tool_call_id' => $event->ctx->toolCallId,
            'arguments' => $event->arguments,
            'decision' => $event->decision,
            'result' => $event->result->auditArray(),
            'duration_ms' => $event->durationMs,
            'source' => $event->ctx->source,
            'token_name' => $event->ctx->tokenName,
        ]);
    }
}
