<?php

namespace App\Ai\Tools;

use App\Ai\Exceptions\NoCustomerAccount;
use App\Models\Customer;
use App\Models\ToolCall;
use App\Models\User;
use ArrayObject;

final class ToolContext
{
    public const SOURCE_WEB = 'web';

    public const SOURCE_MCP = 'mcp';

    private readonly ArrayObject $callCounts;

    public function __construct(
        public readonly User $user,
        public readonly ?int $conversationId = null,
        public readonly string $source = self::SOURCE_WEB,
        public readonly ?string $tokenName = null,
        public readonly ?string $toolCallId = null,
        public readonly ?int $modelCallId = null,
        ?ArrayObject $callCounts = null,
        public readonly ?int $triageRunId = null,
    ) {
        $this->callCounts = $callCounts ?? new ArrayObject;
    }

    public function customer(): Customer
    {
        return $this->user->customer ?? throw new NoCustomerAccount($this->user->id);
    }

    public function forToolCall(string $toolCallId, ?int $modelCallId = null): self
    {
        return new self(
            user: $this->user,
            conversationId: $this->conversationId,
            source: $this->source,
            tokenName: $this->tokenName,
            toolCallId: $toolCallId,
            modelCallId: $modelCallId,
            callCounts: $this->callCounts,
            triageRunId: $this->triageRunId,
        );
    }

    public function toolCallsThisConversation(string $tool): int
    {
        if ($this->conversationId === null) {
            return $this->callCounts[$tool] ?? 0;
        }

        return ToolCall::query()
            ->where('user_id', $this->user->id)
            ->where('conversation_id', $this->conversationId)
            ->where('tool', $tool)
            ->whereIn('decision', [
                ToolCall::DECISION_ALLOWED,
                ToolCall::DECISION_APPROVED,
            ])
            ->count();
    }

    public function rememberCall(string $tool): void
    {
        $this->callCounts[$tool] = ($this->callCounts[$tool] ?? 0) + 1;
    }
}
