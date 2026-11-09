<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;

class HelpArticle
{
    public function __construct(
        public readonly string $category,
        public readonly string $slug,
        public readonly string $title,
        public readonly string $markdown,
        public readonly array $tags = [],
        public readonly ?CarbonImmutable $modifiedAt = null,
    ) {}

    public function path(): string
    {
        return "help/{$this->category}/{$this->slug}.md";
    }

    public function html(): string
    {
        return Str::markdown(
            $this->markdown,
            [
                'heading_permalink' => [
                    'id_prefix' => '',
                    'fragment_prefix' => '',
                    'insert' => 'after',
                    'symbol' => '',
                ],
            ],
            [new HeadingPermalinkExtension],
        );
    }

    public function wordCount(): int
    {
        return str_word_count($this->markdown);
    }
}
