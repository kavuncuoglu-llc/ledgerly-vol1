<?php

namespace App\Ai\Retrieval;

final class LexicalReranker implements Reranker
{
    private const MIN_TERM_LENGTH = 3;

    public function score(string $question, array $passages): array
    {
        $terms = $this->termsOf($question);

        return array_map(
            fn (string $passage): float => $this->overlap($terms, $this->termsOf($passage)),
            array_values($passages),
        );
    }

    private function overlap(array $questionTerms, array $passageTerms): float
    {
        if ($questionTerms === []) {
            return 0.0;
        }

        return count(array_intersect($questionTerms, $passageTerms)) / count($questionTerms);
    }

    private function termsOf(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $matches);

        return array_values(array_unique(array_filter(
            $matches[0],
            fn (string $term): bool => mb_strlen($term) >= self::MIN_TERM_LENGTH,
        )));
    }
}
