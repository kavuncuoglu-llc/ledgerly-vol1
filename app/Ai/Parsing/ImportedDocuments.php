<?php

namespace App\Ai\Parsing;

use App\Support\HelpArticle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use SplFileInfo;

final class ImportedDocuments
{
    public const CATEGORY = 'imports';

    public function __construct(private readonly ParserRouter $router) {}

    public function articlesIn(string $directory): Collection
    {
        return collect(File::allFiles($directory))
            ->filter(fn (SplFileInfo $file): bool => $this->router->supports($file->getPathname()))
            ->sortBy(fn (SplFileInfo $file): string => $file->getPathname())
            ->map(fn (SplFileInfo $file): HelpArticle => $this->articleFrom($file))
            ->values();
    }

    private function articleFrom(SplFileInfo $file): HelpArticle
    {
        $markdown = $this->router->markdown($file->getPathname());

        $slug = Str::slug($file->getBasename('.'.$file->getExtension()));

        return new HelpArticle(
            category: self::CATEGORY,
            slug: $slug,
            title: $this->titleOf($markdown, $slug),
            markdown: $markdown,
            tags: [
                'area' => self::CATEGORY,
            ],
            modifiedAt: CarbonImmutable::createFromTimestamp($file->getMTime()),
        );
    }

    private function titleOf(string $markdown, string $slug): string
    {
        if (preg_match('/^# (.+)$/m', $markdown, $match) === 1) {
            return trim($match[1]);
        }

        return Str::headline($slug);
    }
}
