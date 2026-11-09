<?php

namespace App\Ai\Parsing;

use App\Ai\Exceptions\UnsupportedDocument;

final class ParserRouter
{
    public const EXTENSIONS = [
        'md',
        'markdown',
        'html',
        'htm',
        'docx',
        'pdf',
    ];

    public function __construct(
        private readonly MarkdownParser $markdown,
        private readonly HtmlParser $html,
        private readonly DocxParser $docx,
        private readonly DocumentParser $pdf,
    ) {}

    public function supports(string $path): bool
    {
        return in_array($this->extensionOf($path), self::EXTENSIONS, true);
    }

    public function markdown(string $path): string
    {
        return $this->parserFor($path)->markdown($path);
    }

    private function parserFor(string $path): DocumentParser
    {
        $extension = $this->extensionOf($path);

        return match ($extension) {
            'md', 'markdown' => $this->markdown,
            'html', 'htm' => $this->html,
            'docx' => $this->docx,
            'pdf' => $this->pdf,
            default => throw UnsupportedDocument::extension($path, $extension),
        };
    }

    private function extensionOf(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }
}
