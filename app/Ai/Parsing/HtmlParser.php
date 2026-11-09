<?php

namespace App\Ai\Parsing;

use App\Ai\Exceptions\UnsupportedDocument;
use DOMDocument;
use DOMElement;
use League\HTMLToMarkdown\HtmlConverter;

final class HtmlParser implements DocumentParser
{
    private const CONTENT_ELEMENTS = [
        'main',
        'article',
        'body',
    ];

    private const BOILERPLATE_ELEMENTS = [
        'script',
        'style',
        'nav',
        'header',
        'footer',
        'aside',
        'form',
        'noscript',
    ];

    public function markdown(string $path): string
    {
        $html = @file_get_contents($path);

        if ($html === false) {
            throw UnsupportedDocument::unreadable($path, 'the file cannot be read');
        }

        return $this->fromHtml($html);
    }

    public function fromHtml(string $html): string
    {
        $document = new DOMDocument;

        libxml_use_internal_errors(true);

        $document->loadHTML('<?xml encoding="utf-8"?>'.$html);

        libxml_clear_errors();

        $this->removeBoilerplate($document);

        $converter = new HtmlConverter([
            'header_style' => 'atx',
            'strip_tags' => true,
            'hard_break' => false,
        ]);

        return trim($converter->convert($this->contentHtml($document)))."\n";
    }

    private function removeBoilerplate(DOMDocument $document): void
    {
        foreach (self::BOILERPLATE_ELEMENTS as $tag) {
            $nodes = iterator_to_array($document->getElementsByTagName($tag));

            foreach ($nodes as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
    }

    private function contentHtml(DOMDocument $document): string
    {
        foreach (self::CONTENT_ELEMENTS as $tag) {
            $element = $document->getElementsByTagName($tag)->item(0);

            if ($element instanceof DOMElement) {
                return $document->saveHTML($element);
            }
        }

        return (string) $document->saveHTML();
    }
}
