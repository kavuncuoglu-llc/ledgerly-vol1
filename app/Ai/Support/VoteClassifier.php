<?php

namespace App\Ai\Support;

use App\Ai\Exceptions\StructuredOutputFailed;
use App\Ai\Exceptions\StructuredRefusal;
use App\Enums\TicketCategory;
use Illuminate\Support\Facades\Log;

final class VoteClassifier
{
    public const PROFILE = 'assistant';

    public function __construct(private readonly ClassifyTicket $classify) {}

    public function sample(string $message, int $samples): CategoryVotes
    {
        $categories = [];

        for ($vote = 0; $vote < $samples; $vote++) {
            $categories[] = $this->voteOnce($message);
        }

        return new CategoryVotes($categories);
    }

    private function voteOnce(string $message): TicketCategory
    {
        try {
            return ($this->classify)($message, ['profile' => self::PROFILE]);
        } catch (StructuredOutputFailed|StructuredRefusal $exception) {
            Log::warning('A classification vote failed and counts as other', [
                'reason' => $exception->getMessage(),
            ]);

            return TicketCategory::Other;
        }
    }
}
