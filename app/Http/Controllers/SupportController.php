<?php

namespace App\Http\Controllers;

use App\Ai\Support\SupportAssistant;
use App\Http\Requests\AskRequest;
use App\Http\Resources\ToolRunResource;
use Illuminate\Http\JsonResponse;

class SupportController extends Controller
{
    public function ask(AskRequest $request, SupportAssistant $assistant): JsonResponse
    {
        $conversation = $request->conversation();

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
