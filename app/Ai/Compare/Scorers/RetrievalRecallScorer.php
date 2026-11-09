<?php

namespace App\Ai\Compare\Scorers;

use App\Ai\Compare\Score;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Retrieval\CitationChecker;
use App\Ai\Retrieval\RetrievedChunk;

final class RetrievalRecallScorer implements Scorer
{
    private const ABSTAIN_PATTERN = "/(could not|couldn't|can't|cannot|unable to|don't|do not) (find|help|answer)|outside (of )?what|support ticket|not (in|covered)/i";

    public function __construct(
        private readonly array $expectedLabels,
        private readonly array $labelToChunk,
        private readonly CitationChecker $citations,
    ) {}

    public function name(): string
    {
        return 'recall';
    }

    public function score(ChatResponse $response): Score
    {
        $answer = (string) $response->content;

        $cited = $this->citations->check($answer, $this->labelToChunk, requireCitations: false)->cited;

        if ($this->expectedLabels === []) {
            return $this->scoreAbstention($answer, $cited);
        }

        return $this->scoreCitation($cited);
    }

    private function scoreCitation(array $cited): Score
    {
        $labels = implode(', ', array_keys($cited));

        foreach ($cited as $chunk) {
            if ($this->isExpected($chunk)) {
                return new Score($this->name(), 1.0, "cited {$labels}");
            }
        }

        return new Score($this->name(), 0.0, $labels === '' ? 'cited nothing' : "cited {$labels}, not the expected source");
    }

    private function scoreAbstention(string $answer, array $cited): Score
    {
        if ($cited !== []) {
            return new Score($this->name(), 0.0, 'cited a passage for an off-topic question');
        }

        if (preg_match(self::ABSTAIN_PATTERN, $answer) !== 1) {
            return new Score($this->name(), 0.0, 'answered an off-topic question');
        }

        return new Score($this->name(), 1.0, 'declined');
    }

    private function isExpected(RetrievedChunk $chunk): bool
    {
        foreach ($this->expectedLabels as $label) {
            if ($chunk->covers($label)) {
                return true;
            }
        }

        return false;
    }
}
