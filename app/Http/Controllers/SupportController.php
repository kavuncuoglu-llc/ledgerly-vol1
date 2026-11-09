<?php

namespace App\Http\Controllers;

use App\Ai\Support\SupportAssistant;
use App\Http\Requests\AskRequest;
use Illuminate\Http\JsonResponse;

class SupportController extends Controller
{
    public function ask(AskRequest $request, SupportAssistant $assistant): JsonResponse
    {
        $reply = $assistant->answer($request->user(), $request->validated('question'));

        return response()->json([
            'answer' => $reply->content,
            'truncated' => $reply->finishReason === 'length',
        ]);
    }
}
