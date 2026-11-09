<?php

namespace App\Http\Controllers;

use App\Ai\Tools\ApprovalOutcome;
use App\Ai\Tools\ToolApprovals;
use App\Http\Requests\RespondToToolApprovalRequest;
use App\Http\Resources\PendingToolCallResource;
use App\Http\Resources\ToolRunResource;
use App\Models\PendingToolCall;
use Illuminate\Http\JsonResponse;

class ToolApprovalController extends Controller
{
    public function show(RespondToToolApprovalRequest $request, PendingToolCall $pendingToolCall): JsonResponse
    {
        return response()->json([
            'data' => new PendingToolCallResource($pendingToolCall),
        ]);
    }

    public function approve(RespondToToolApprovalRequest $request, PendingToolCall $pendingToolCall, ToolApprovals $approvals): JsonResponse
    {
        return $this->respond($approvals->approve($pendingToolCall));
    }

    public function decline(RespondToToolApprovalRequest $request, PendingToolCall $pendingToolCall, ToolApprovals $approvals): JsonResponse
    {
        return $this->respond($approvals->decline($pendingToolCall));
    }

    private function respond(ApprovalOutcome $outcome): JsonResponse
    {
        $body = [
            'data' => new PendingToolCallResource($outcome->pending),
        ];

        if ($outcome->run === null) {
            return response()->json($body);
        }

        return response()->json([
            ...$body,
            ...(new ToolRunResource($outcome->run))->resolve(),
        ]);
    }
}
