<?php

namespace App\Ai\Tools;

use Illuminate\Support\Facades\Gate;

final class ToolGate
{
    public function check(Tool $tool, array $args, ToolContext $ctx, bool $approved = false): GateDecision
    {
        $permitted = Gate::forUser($ctx->user)->allows('use-tool', [
            $tool->name(),
            $ctx->tokenName,
        ]);

        if (! $permitted) {
            return GateDecision::deny('not permitted');
        }

        if ($ctx->toolCallsThisConversation($tool->name()) >= $tool->perConversationLimit()) {
            return GateDecision::deny('limit reached');
        }

        return match ($tool->kind()) {
            ToolKind::Read => GateDecision::allow(),
            ToolKind::WriteLowRisk => GateDecision::allow(audit: true),
            ToolKind::WriteHighRisk => $this->highRiskDecision($tool, $args, $ctx, $approved),
        };
    }

    private function highRiskDecision(Tool $tool, array $args, ToolContext $ctx, bool $approved): GateDecision
    {
        if ($approved) {
            return GateDecision::allow(audit: true);
        }

        return GateDecision::requireApproval(
            summary: $tool->describeIntent($args, $ctx),
        );
    }
}
