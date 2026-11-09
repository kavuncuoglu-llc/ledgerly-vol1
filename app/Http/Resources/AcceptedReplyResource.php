<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcceptedReplyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'reply_id' => $this->reply->id,
            'channel' => $this->reply->channelName(),
            'conversation_id' => $this->reply->conversation_id,
            'status' => $this->reply->status,
            'queue_depth' => $this->queueDepth,
        ];
    }
}
