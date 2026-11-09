<?php

namespace App\Ai\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

final class ConversationNotOpen extends RuntimeException
{
    public function __construct(public readonly string $status)
    {
        parent::__construct("This conversation is already {$status}.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
        ], 409);
    }
}
