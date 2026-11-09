<?php

namespace App\Ai\Compare\Cases;

use App\Ai\Compare\CompareCase;
use App\Ai\Compare\Scorers\JudgeScorer;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Exceptions\UnusableComparison;
use App\Models\RecordedRequest;

final class RecordedRequestCases
{
    public const CATEGORY = 'recorded';

    private const BACKGROUND_LIMIT = 6000;

    public function forFeature(string $feature, int $limit, ?ChatModel $judge): BuiltCases
    {
        if ($judge === null) {
            throw UnusableComparison::replayNeedsJudge();
        }

        $rows = RecordedRequest::query()
            ->where('feature', $feature)
            ->orderByDesc('id')
            ->limit(max($limit, 1) * 4)
            ->get();

        $replayable = $rows->filter(fn (RecordedRequest $row): bool => ($row->request['tools'] ?? []) === []);

        if ($replayable->isEmpty()) {
            throw UnusableComparison::noRecordedRequests($feature);
        }

        $chosen = Sampler::spread($replayable->all(), $limit);

        $skipped = $rows->count() - $replayable->count();

        return new BuiltCases(
            cases: array_map(fn (RecordedRequest $row): CompareCase => $this->caseFor($row, $judge), $chosen),
            skipped: $skipped === 0
                ? []
                : ["{$skipped} recorded request(s) offer tools and were not replayed"],
        );
    }

    private function caseFor(RecordedRequest $row, ChatModel $judge): CompareCase
    {
        $request = $this->withProfile(ChatRequest::fromRecorded($row->request), $row->feature);

        return new CompareCase(
            id: "recorded-{$row->id}",
            feature: $row->feature,
            category: self::CATEGORY,
            request: $request,
            scorers: [new JudgeScorer($judge, $this->questionOf($request), $this->backgroundOf($request))],
            sensitive: true,
        );
    }

    private function withProfile(ChatRequest $request, string $feature): ChatRequest
    {
        $profile = $request->profile ?? FeatureProfile::of($feature);

        if ($profile === null) {
            return $request;
        }

        return $request->withProfile($profile);
    }

    private function backgroundOf(ChatRequest $request): string
    {
        $background = [];

        foreach (array_slice($request->messages, 0, -1) as $message) {
            if (is_string($message['content'] ?? null)) {
                $background[] = $message['content'];
            }
        }

        return mb_substr(implode("\n\n", $background), 0, self::BACKGROUND_LIMIT);
    }

    private function questionOf(ChatRequest $request): string
    {
        $userMessages = array_values(array_filter($request->messages, fn (array $message): bool => ($message['role'] ?? '') === 'user'));

        $last = end($userMessages);

        if ($last === false) {
            return '';
        }

        return is_string($last['content'] ?? null)
            ? $last['content']
            : (string) json_encode($last['content'] ?? '');
    }
}
