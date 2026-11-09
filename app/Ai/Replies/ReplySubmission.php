<?php

namespace App\Ai\Replies;

use App\Models\Reply;

final readonly class ReplySubmission
{
    public function __construct(
        public Reply $reply,
        public int $queueDepth,
    ) {}
}
