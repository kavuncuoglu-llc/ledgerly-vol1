<?php

namespace App\Ai\Embeddings;

use App\Ai\Tokens\TokenCounter;

final class ParentChildChunker
{
    public function __construct(
        private readonly TokenCounter $tokens,
        private readonly int $maxChildTokens,
    ) {}

    public function children(Chunk $parent): array
    {
        $groups = $this->grouped($this->units($this->bodyParagraphs($parent)));

        return array_map(
            fn (string $text, int $index): ChildChunk => new ChildChunk(
                index: $index,
                text: $text,
                tokens: $this->tokens->count($text),
            ),
            $groups,
            array_keys($groups),
        );
    }

    private function bodyParagraphs(Chunk $parent): array
    {
        $paragraphs = preg_split('/\n\s*\n/', trim($parent->content), flags: PREG_SPLIT_NO_EMPTY);

        return array_slice($paragraphs, 1);
    }

    private function units(array $paragraphs): array
    {
        $units = [];

        $pendingHeading = '';

        foreach ($paragraphs as $paragraph) {
            if (str_starts_with($paragraph, '## ')) {
                $pendingHeading = $paragraph;

                continue;
            }

            array_push($units, ...$this->splitOversized($this->withHeading($pendingHeading, $paragraph)));

            $pendingHeading = '';
        }

        return $units;
    }

    private function withHeading(string $heading, string $paragraph): string
    {
        if ($heading === '') {
            return $paragraph;
        }

        return $heading."\n\n".$paragraph;
    }

    private function splitOversized(string $unit): array
    {
        if ($this->tokens->count($unit) <= $this->maxChildTokens) {
            return [$unit];
        }

        $sentences = preg_split('/(?<=[.!?])\s+/', $unit, flags: PREG_SPLIT_NO_EMPTY);

        return $this->grouped($sentences, ' ');
    }

    private function grouped(array $units, string $glue = "\n\n"): array
    {
        $groups = [];

        $current = [];

        foreach ($units as $unit) {
            $candidate = [...$current, $unit];

            if ($current !== [] && $this->tokens->count(implode($glue, $candidate)) > $this->maxChildTokens) {
                $groups[] = implode($glue, $current);

                $candidate = [$unit];
            }

            $current = $candidate;
        }

        if ($current !== []) {
            $groups[] = implode($glue, $current);
        }

        return $groups;
    }
}
