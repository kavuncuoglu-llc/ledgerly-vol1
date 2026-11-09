<?php

namespace App\Http\Resources;

use App\Ai\Contracts\FinishReason;
use App\Ai\Tools\ToolResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ToolRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'answer' => $this->answer(),
            'sources' => $this->sources,
            'citations_ok' => $this->citationsOk,
            'conversation_id' => $this->conversationId,
            'truncated' => $this->response->finishReason === FinishReason::Length,
            'rounds' => $this->rounds,
            'hit_cap' => $this->hitCap,
            'pending' => array_map(
                fn (ToolResult $result): array => [
                    'id' => $result->pendingId,
                    'summary' => $result->summary,
                    'approval_url' => $result->approvalUrl,
                ],
                $this->pending,
            ),
        ];
    }
}
