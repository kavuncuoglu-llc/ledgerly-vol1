<?php

namespace App\Ai\Retrieval;

use Illuminate\Support\Str;

final class CitationChecker
{
    private const LABEL_PATTERN = '/\[([^\]]+)\]/u';

    private const FACTUAL_PATTERN = '/\d|\b(fee|fees|policy|days?|percent|due|charged|applied|refund|refunds)\b/i';

    private const STOP_WORDS = [
        'about', 'after', 'also', 'because', 'before', 'being', 'could', 'does', 'from', 'have', 'into', 'more', 'only',
        'other', 'over', 'that', 'their', 'there', 'these', 'they', 'this', 'those', 'unless', 'were', 'what', 'when',
        'which', 'while', 'will', 'with', 'would', 'your', 'ledgerly',
    ];

    public function check(string $answer, array $labelToChunk, bool $requireCitations = true): CitationReport
    {
        $errors = [];

        $cited = $this->citedLabels($answer);

        foreach ($cited as $label) {
            if (! isset($labelToChunk[$label])) {
                $errors[] = "Citation [{$label}] does not match any provided passage.";
            }
        }

        if ($requireCitations) {
            array_push($errors, ...$this->sentenceErrors($answer, $labelToChunk));
        }

        return new CitationReport(
            errors: $errors,
            cited: array_intersect_key($labelToChunk, array_flip($cited)),
        );
    }

    public function sentences(string $answer): array
    {
        $normalized = (string) preg_replace('/^\s*(?:\d+[.)]|[-*•])\s+/mu', '', $answer);

        $normalized = (string) preg_replace('/([.!?])\s*((?:\[[^\]]+\]\s*)+)/u', '$2$1 ', $normalized);

        $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z0-9"\'(])|\n+/u', $normalized, flags: PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter(array_map('trim', $sentences), fn (string $sentence): bool => $sentence !== ''));
    }

    private function citedLabels(string $answer): array
    {
        preg_match_all(self::LABEL_PATTERN, $answer, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function sentenceErrors(string $answer, array $labelToChunk): array
    {
        $errors = [];

        foreach ($this->sentences($answer) as $sentence) {
            array_push($errors, ...$this->errorsOfSentence($sentence, $labelToChunk));
        }

        return $errors;
    }

    private function errorsOfSentence(string $sentence, array $labelToChunk): array
    {
        $labels = $this->citedLabels($sentence);

        if ($labels === []) {
            return $this->isFactual($sentence)
                ? ['Uncited factual sentence: "'.Str::limit($sentence, 80).'"']
                : [];
        }

        $errors = [];

        foreach ($labels as $label) {
            if (isset($labelToChunk[$label]) && ! $this->overlaps($sentence, $labelToChunk[$label]->content)) {
                $errors[] = "Sentence cites [{$label}] but shares no key terms or numbers with it.";
            }
        }

        return $errors;
    }

    private function isFactual(string $sentence): bool
    {
        return (bool) preg_match(self::FACTUAL_PATTERN, $sentence);
    }

    private function overlaps(string $sentence, string $chunk): bool
    {
        $claim = (string) preg_replace(self::LABEL_PATTERN, '', $sentence);

        $numbers = $this->numbersIn($claim);

        if ($numbers !== []) {
            return array_diff($numbers, $this->numbersIn($chunk)) === [];
        }

        return $this->sharesKeyTerms($claim, $chunk);
    }

    private function numbersIn(string $text): array
    {
        preg_match_all('/\d[\d,]*(?:\.\d+)?/', $text, $matches);

        return array_values(array_unique(array_map($this->normalizedNumber(...), $matches[0])));
    }

    private function normalizedNumber(string $number): string
    {
        $plain = rtrim(str_replace(',', '', $number), ',');

        if (str_contains($plain, '.')) {
            return rtrim(rtrim($plain, '0'), '.');
        }

        return $plain;
    }

    private function sharesKeyTerms(string $claim, string $chunk): bool
    {
        $claimTerms = $this->keyTerms($claim);

        if ($claimTerms === []) {
            return true;
        }

        $shared = array_intersect($claimTerms, $this->keyTerms($chunk));

        return count($shared) >= min(2, count($claimTerms));
    }

    private function keyTerms(string $text): array
    {
        preg_match_all('/[a-z]{4,}/', Str::lower($text), $matches);

        $words = array_diff($matches[0], self::STOP_WORDS);

        return array_values(array_unique(array_map(fn (string $word): string => Str::singular($word), $words)));
    }
}
