<?php

namespace App\Http\Controllers;

use App\Ai\Memory\MemoryStore;
use App\Http\Requests\ForgetMemoryRequest;
use App\Http\Resources\UserMemoryResource;
use App\Models\UserMemory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemoryController extends Controller
{
    public function index(Request $request, MemoryStore $memories): JsonResponse
    {
        return response()->json([
            'data' => UserMemoryResource::collection($memories->all($request->user())),
        ]);
    }

    public function destroy(ForgetMemoryRequest $request, UserMemory $userMemory, MemoryStore $memories): JsonResponse
    {
        $memories->forget($userMemory);

        return response()->json([], 204);
    }
}
