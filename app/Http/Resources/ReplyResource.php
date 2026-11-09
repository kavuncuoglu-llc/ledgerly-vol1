<?php

namespace App\Http\Resources;

use App\Ai\Replies\ReplyLifecycle;
use App\Ai\Streaming\ReplyBuffer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReplyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $chunks = $this->chunks();

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'channel' => $this->channelName(),
            'question' => $this->question,
            'status' => $this->status,
            'content' => $this->visibleContent($chunks),
            'chunks' => $chunks,
            'sources' => $this->sources ?? [],
            'citations_ok' => $this->citations_ok,
            'paused_for' => $this->paused_for,
            'approvals' => app(ReplyLifecycle::class)->approvalsFor($this->resource),
            'feedback' => $this->feedback,
        ];
    }

    private function chunks(): array
    {
        if ($this->isFinished()) {
            return [];
        }

        return array_values(app(ReplyBuffer::class)->chunks($this->id));
    }

    private function visibleContent(array $chunks): string
    {
        if ($this->isFinished()) {
            return (string) $this->content;
        }

        return implode('', $chunks);
    }
}
