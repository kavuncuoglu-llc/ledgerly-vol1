<?php

use App\Models\Reply;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('reply.{replyId}', fn (User $user, string $replyId): bool => Reply::query()
    ->whereKey($replyId)
    ->where('user_id', $user->id)
    ->exists());
