<?php

namespace App\Support;

use Illuminate\Support\Str;

class HelpArticle
{
    public function __construct(
        public readonly string $category,
        public readonly string $slug,
        public readonly string $title,
        public readonly string $markdown,
    ) {}

    public function path(): string
    {
        return "help/{$this->category}/{$this->slug}.md";
    }

    public function html(): string
    {
        return Str::markdown($this->markdown);
    }

    public function wordCount(): int
    {
        return str_word_count($this->markdown);
    }
}
