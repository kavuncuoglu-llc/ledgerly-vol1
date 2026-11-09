<?php

namespace App\Ai\Compare\Cases;

use App\Ai\Compare\CompareCase;
use App\Ai\Compare\Scorers\JudgeScorer;
use App\Ai\Compare\Scorers\RetrievalRecallScorer;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Retrieval\CitationChecker;
use App\Ai\Retrieval\GoldenQuestion;
use App\Ai\Retrieval\GoldenSet;
use App\Ai\Retrieval\RetrievalScope;
use App\Ai\Retrieval\RetrievedChunk;
use App\Ai\Retrieval\Retriever;

final class SupportReplyCases
{
    public const FEATURE = 'support.reply';

    public const OFF_TOPIC = 'off-topic';

    public function __construct(
        private readonly Retriever $retriever,
        private readonly CitationChecker $citations,
    ) {}

    public function fromFile(string $path, int $limit = 0, ?ChatModel $judge = null): BuiltCases
    {
        $questions = Sampler::spread(GoldenSet::fromFile($path), $limit);

        $cases = [];

        $skipped = [];

        foreach ($questions as $question) {
            $case = $this->caseFor($question, $judge);

            if ($case === null) {
                $skipped[] = "#{$question->id} {$question->question}: the expected source was not retrieved, so no model could be blamed for missing it";

                continue;
            }

            $cases[] = $case;
        }

        return new BuiltCases($cases, $skipped);
    }

    private function caseFor(GoldenQuestion $question, ?ChatModel $judge): ?CompareCase
    {
        $chunks = $this->retriever->retrieve($question->question, new RetrievalScope, k: (int) config('ai.retrieval.k'));

        $assembled = SupportPrompt::assemble(question: $question->question, contextChunks: $chunks);

        $category = $this->categoryOf($question, $assembled->labelMap);

        if ($category === null) {
            return null;
        }

        return new CompareCase(
            id: "golden-{$question->id}",
            feature: self::FEATURE,
            category: $category,
            request: $this->requestFor($assembled->messages),
            scorers: $this->scorers($question, $assembled->labelMap, $judge),
        );
    }

    private function scorers(GoldenQuestion $question, array $labelMap, ?ChatModel $judge): array
    {
        $scorers = [new RetrievalRecallScorer($question->chunks, $labelMap, $this->citations)];

        if ($judge === null) {
            return $scorers;
        }

        $scorers[] = new JudgeScorer($judge, $question->question, $this->passagesOf($labelMap));

        return $scorers;
    }

    private function categoryOf(GoldenQuestion $question, array $labelMap): ?string
    {
        if ($question->expectsNothing()) {
            return self::OFF_TOPIC;
        }

        foreach ($labelMap as $chunk) {
            foreach ($question->chunks as $label) {
                if ($chunk->covers($label)) {
                    return $chunk->category();
                }
            }
        }

        return null;
    }

    private function passagesOf(array $labelMap): string
    {
        return implode("\n\n", array_map(
            fn (RetrievedChunk $chunk, string $label): string => "[{$label}]\n{$chunk->body()}",
            $labelMap,
            array_keys($labelMap),
        ));
    }

    private function requestFor(array $messages): ChatRequest
    {
        return new ChatRequest(
            messages: $messages,
            feature: self::FEATURE,
            options: [
                'max_tokens' => (int) config('ai.budget.answer'),
            ],
            profile: FeatureProfile::of(self::FEATURE),
            promptVersion: SupportPrompt::VERSION,
        );
    }
}
