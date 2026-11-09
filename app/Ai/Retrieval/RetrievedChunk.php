<?php

namespace App\Ai\Retrieval;

use Illuminate\Support\Str;
use League\CommonMark\Normalizer\SlugNormalizer;

final readonly class RetrievedChunk
{
    private const PATH_SEPARATOR = ' › ';

    private const INTRODUCTION = 'Introduction';

    public function __construct(
        public int $id,
        public string $source,
        public string $title,
        public string $headingPath,
        public string $content,
        public string $helpVersion = '',
        public float $similarity = 0.0,
        public float $rrf = 0.0,
        public bool $keywordMatched = false,
    ) {}

    public function category(): string
    {
        return basename(dirname($this->source));
    }

    public function slug(): string
    {
        return basename($this->source, '.md');
    }

    public function section(): string
    {
        $segments = explode(self::PATH_SEPARATOR, $this->headingPath);

        return end($segments);
    }

    public function label(): string
    {
        return $this->slug().self::PATH_SEPARATOR.$this->section();
    }

    public function body(): string
    {
        $firstBreak = strpos($this->content, "\n\n");

        if ($firstBreak === false) {
            return $this->content;
        }

        $firstLine = substr($this->content, 0, $firstBreak);

        if (! Str::contains($firstLine, $this->section())) {
            return $this->content;
        }

        return ltrim(substr($this->content, $firstBreak));
    }

    public function url(): string
    {
        $article = "/help/{$this->category()}/{$this->slug()}";

        if ($this->section() === self::INTRODUCTION) {
            return $article;
        }

        $anchor = (new SlugNormalizer)->normalize($this->section());

        return "{$article}#{$anchor}";
    }

    public function covers(string $goldenLabel): bool
    {
        if (self::normalizeLabel($this->label()) === self::normalizeLabel($goldenLabel)) {
            return true;
        }

        [$slug, $section] = array_pad(explode('›', $goldenLabel, 2), 2, '');

        if (trim($slug) !== $this->slug()) {
            return false;
        }

        return Str::contains($this->content, '## '.trim($section));
    }

    public static function normalizeLabel(string $label): string
    {
        return (string) preg_replace('/\s*›\s*/u', '›', trim($label));
    }
}
