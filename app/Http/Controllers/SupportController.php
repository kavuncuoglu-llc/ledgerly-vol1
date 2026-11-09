<?php

namespace App\Http\Controllers;

use App\Ai\Replies\ReplyDispatcher;
use App\Ai\Support\SupportAssistant;
use App\Http\Requests\AskRequest;
use App\Http\Resources\AcceptedReplyResource;
use App\Http\Resources\ToolRunResource;
use Illuminate\Http\JsonResponse;

class SupportController extends Controller
{
    public function ask(AskRequest $request, SupportAssistant $assistant, ReplyDispatcher $replies): JsonResponse
    {
        $conversation = $request->conversation();

        if (config('ai.replies.enabled')) {
            $submission = $replies->submit(
                user: $request->user(),
                question: $request->validated('question'),
                conversation: $conversation->exists
                    ? $conversation
                    : null,
                requestKey: (string) $request->validated('request_id'),
            );

            return response()->json((new AcceptedReplyResource($submission))->resolve(), 202);
        }

        $run = $assistant->answer(
            user: $request->user(),
            question: $request->validated('question'),
            conversation: $conversation->exists
                ? $conversation
                : null,
        );

        return response()->json((new ToolRunResource($run))->resolve());
    }
}
