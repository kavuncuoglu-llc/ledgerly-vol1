<?php

namespace App\Http\Resources;

use App\Ai\Tools\ToolResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ToolRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'answer' => $this->answer(),
            'truncated' => $this->response->finishReason === 'length',
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
