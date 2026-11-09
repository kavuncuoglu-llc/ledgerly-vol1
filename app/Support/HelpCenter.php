<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use SplFileInfo;

class HelpCenter
{
    private const LIST_TAGS = [
        'plans',
        'audience',
    ];

    private const NON_TAG_KEYS = [
        'title',
        'category',
    ];

    public function __construct(
        private readonly string $directory,
    ) {}

    public function all(): Collection
    {
        return collect(File::allFiles($this->directory))
            ->filter(fn (SplFileInfo $file) => $file->getExtension() === 'md')
            ->map(fn (SplFileInfo $file) => $this->articleFrom($file))
            ->sortBy(fn (HelpArticle $article) => $article->category.'/'.$article->slug)
            ->values();
    }

    public function byCategory(): Collection
    {
        return $this->all()->groupBy(fn (HelpArticle $article) => $article->category);
    }

    public function find(string $category, string $slug): HelpArticle
    {
        $file = "{$this->directory}/{$category}/{$slug}.md";

        abort_unless(File::exists($file), 404);

        return $this->articleFrom(new SplFileInfo($file));
    }

    private function articleFrom(SplFileInfo $file): HelpArticle
    {
        $contents = File::get($file->getPathname());

        [$frontMatter, $markdown] = $this->splitFrontMatter($contents);

        $category = basename($file->getPath());

        $slug = $file->getBasename('.md');

        return new HelpArticle(
            category: $category,
            slug: $slug,
            title: $frontMatter['title'] ?? Str::headline($slug),
            markdown: $markdown,
            tags: $this->tagsFrom($frontMatter, $category),
            modifiedAt: CarbonImmutable::createFromTimestamp($file->getMTime()),
        );
    }

    private function tagsFrom(array $frontMatter, string $category): array
    {
        $tags = [
            'area' => $category,
        ];

        foreach (array_diff_key($frontMatter, array_flip(self::NON_TAG_KEYS)) as $key => $value) {
            $tags[$key] = in_array($key, self::LIST_TAGS, true)
                ? $this->listOf($value)
                : $value;
        }

        return $tags;
    }

    private function listOf(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    private function splitFrontMatter(string $contents): array
    {
        if (! str_starts_with($contents, "---\n")) {
            return [[], $contents];
        }

        $end = strpos($contents, "\n---\n", 4);

        if ($end === false) {
            return [[], $contents];
        }

        $block = substr($contents, 4, $end - 4);

        $markdown = ltrim(substr($contents, $end + 5));

        return [$this->parseFrontMatter($block), $markdown];
    }

    private function parseFrontMatter(string $block): array
    {
        $values = [];

        foreach (explode("\n", $block) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);

            $values[trim($key)] = trim($value);
        }

        return $values;
    }
}
