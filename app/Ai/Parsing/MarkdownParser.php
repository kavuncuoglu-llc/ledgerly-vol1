<?php

namespace App\Ai\Parsing;

use App\Ai\Exceptions\UnsupportedDocument;

final class MarkdownParser implements DocumentParser
{
    public function markdown(string $path): string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw UnsupportedDocument::unreadable($path, 'the file cannot be read');
        }

        return $contents;
    }
}
