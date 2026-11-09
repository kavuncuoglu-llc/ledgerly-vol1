<?php

namespace App\Http\Controllers;

use App\Ai\Conversations\ConversationStore;
use App\Http\Requests\CloseConversationRequest;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;

class ConversationController extends Controller
{
    public function close(CloseConversationRequest $request, Conversation $conversation, ConversationStore $store): JsonResponse
    {
        $store->close($conversation);

        return response()->json([
            'data' => new ConversationResource($conversation->fresh()),
        ]);
    }
}
