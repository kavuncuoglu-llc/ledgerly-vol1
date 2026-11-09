<?php

namespace App\Ai\Embeddings;

use App\Ai\Tokens\TokenCounter;

final class MarkdownChunker
{
    private const INTRODUCTION_HEADING = 'Introduction';

    public function __construct(
        private readonly TokenCounter $tokens,
        private readonly int $minTokens,
        private readonly int $maxTokens,
    ) {}

    public function chunk(string $title, string $markdown): array
    {
        $units = [];

        foreach ($this->sections($markdown) as $section) {
            array_push($units, ...$this->splitSection($title, $section));
        }

        return array_map(
            fn (array $unit): Chunk => $this->makeChunk($unit),
            $this->mergeSmallNeighbours($units),
        );
    }

    private function sections(string $markdown): array
    {
        $sections = [];
        $heading = self::INTRODUCTION_HEADING;
        $lines = [];
        $insideFence = false;

        foreach (explode("\n", $this->withoutTitleLine($markdown)) as $line) {
            if (str_starts_with($line, '```')) {
                $insideFence = ! $insideFence;
            }

            if (! $insideFence && str_starts_with($line, '## ')) {
                $sections[] = $this->section($heading, $lines);
                $heading = trim(substr($line, 3));
                $lines = [];

                continue;
            }

            $lines[] = $line;
        }

        $sections[] = $this->section($heading, $lines);

        return array_values(array_filter($sections, fn (array $section): bool => $section['paragraphs'] !== []));
    }

    private function section(string $heading, array $lines): array
    {
        $paragraphs = preg_split('/\n\s*\n/', trim(implode("\n", $lines)), flags: PREG_SPLIT_NO_EMPTY);

        return [
            'heading' => $heading,
            'paragraphs' => $paragraphs,
        ];
    }

    private function withoutTitleLine(string $markdown): string
    {
        return (string) preg_replace('/\A\s*# [^\n]*\n/', '', $markdown);
    }

    private function splitSection(string $title, array $section): array
    {
        $label = "{$title} › {$section['heading']}";
        $units = [];
        $current = [];

        foreach ($section['paragraphs'] as $paragraph) {
            $candidate = [...$current, $paragraph];

            if ($current !== [] && $this->tokensOf($label, $candidate) > $this->maxTokens) {
                $units[] = $this->unit($label, $current, $section['heading']);
                $candidate = [$paragraph];
            }

            $current = $candidate;
        }

        $units[] = $this->unit($label, $current, $section['heading']);

        return $units;
    }

    private function mergeSmallNeighbours(array $units): array
    {
        $merged = [];

        foreach ($units as $unit) {
            $previous = end($merged);

            if ($previous !== false && $this->shouldAbsorb($previous, $unit)) {
                $merged[array_key_last($merged)] = $this->absorb($previous, $unit);

                continue;
            }

            $merged[] = $unit;
        }

        return $merged;
    }

    private function shouldAbsorb(array $previous, array $next): bool
    {
        if ($this->tokensOf($previous['label'], $previous['paragraphs']) >= $this->minTokens) {
            return false;
        }

        return $this->tokensOf($previous['label'], $this->absorbedParagraphs($previous, $next)) <= $this->maxTokens;
    }

    private function absorb(array $previous, array $next): array
    {
        return $this->unit(
            $previous['label'],
            $this->absorbedParagraphs($previous, $next),
            $previous['section'],
        );
    }

    private function absorbedParagraphs(array $previous, array $next): array
    {
        return [
            ...$previous['paragraphs'],
            '## '.$next['section'],
            ...$next['paragraphs'],
        ];
    }

    private function unit(string $label, array $paragraphs, string $section): array
    {
        return [
            'label' => $label,
            'paragraphs' => $paragraphs,
            'section' => $section,
        ];
    }

    private function makeChunk(array $unit): Chunk
    {
        $content = $this->render($unit['label'], $unit['paragraphs']);

        return new Chunk(
            heading: $unit['label'],
            content: $content,
            tokens: $this->tokens->count($content),
        );
    }

    private function tokensOf(string $label, array $paragraphs): int
    {
        return $this->tokens->count($this->render($label, $paragraphs));
    }

    private function render(string $label, array $paragraphs): string
    {
        return $label."\n\n".implode("\n\n", $paragraphs);
    }
}
