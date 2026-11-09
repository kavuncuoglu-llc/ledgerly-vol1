<?php

namespace App\Http\Controllers;

use App\Ai\Streaming\ReplyEventStream;
use App\Http\Requests\OwnReplyRequest;
use App\Http\Requests\ReplyFeedbackRequest;
use App\Http\Resources\ReplyResource;
use App\Models\Reply;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReplyController extends Controller
{
    public function show(OwnReplyRequest $request, Reply $reply): JsonResponse
    {
        return response()->json([
            'data' => new ReplyResource($reply),
        ]);
    }

    public function feedback(ReplyFeedbackRequest $request, Reply $reply): JsonResponse
    {
        if ($reply->status !== Reply::STATUS_COMPLETED) {
            return response()->json(['message' => 'Only a finished reply can be rated.'], 409);
        }

        $reply->update(['feedback' => $request->validated('feedback')]);

        return response()->json([
            'data' => new ReplyResource($reply),
        ]);
    }

    public function stream(OwnReplyRequest $request, Reply $reply, ReplyEventStream $events): StreamedResponse
    {
        $offset = $this->resumeOffset($request);

        return response()->stream(
            fn () => $events->relay($reply, $offset),
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    private function resumeOffset(OwnReplyRequest $request): int
    {
        $lastEventId = $request->header('Last-Event-ID');

        if ($lastEventId !== null && is_numeric($lastEventId)) {
            return (int) $lastEventId + 1;
        }

        return max((int) $request->query('after', 0), 0);
    }
}
