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
        $run = $assistant->answer($request->user(), $request->validated('question'));

        return response()->json((new ToolRunResource($run))->resolve());
    }
}
