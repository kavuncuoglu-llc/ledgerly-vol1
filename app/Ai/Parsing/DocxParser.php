<?php

namespace App\Ai\Parsing;

use App\Ai\Exceptions\UnsupportedDocument;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Link;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;
use Throwable;

final class DocxParser implements DocumentParser
{
    public function markdown(string $path): string
    {
        try {
            $document = IOFactory::load($path, 'Word2007');
        } catch (Throwable $exception) {
            throw UnsupportedDocument::unreadable($path, $exception->getMessage());
        }

        $blocks = [];

        foreach ($document->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $blocks[] = $this->block($element);
            }
        }

        return trim(implode("\n\n", array_filter($blocks, fn (string $block): bool => $block !== '')))."\n";
    }

    private function block(AbstractElement $element): string
    {
        return match (true) {
            $element instanceof Title => str_repeat('#', max(1, min(6, (int) $element->getDepth()))).' '.$this->textOf($element->getText()),
            $element instanceof Table => $this->table($element),
            $element instanceof ListItem => '- '.$this->textOf($element->getTextObject()),
            $element instanceof ListItemRun => '- '.$this->textOf($element),
            $element instanceof TextRun => $this->paragraph($element),
            $element instanceof Text => $this->textOf($element),
            $element instanceof TextBreak => '',
            default => '',
        };
    }

    private function paragraph(TextRun $run): string
    {
        $level = $this->headingLevel($run->getParagraphStyle()?->getStyleName());

        $text = $this->textOf($run);

        if ($level === 0) {
            return $text;
        }

        if ($text === '') {
            return '';
        }

        return str_repeat('#', $level).' '.$text;
    }

    private function headingLevel(?string $styleName): int
    {
        if ($styleName === null) {
            return 0;
        }

        if (preg_match('/^(?:Heading|Title)\s*(\d)?$/i', $styleName, $match) !== 1) {
            return 0;
        }

        return (int) ($match[1] ?? 1);
    }

    private function table(Table $table): string
    {
        $rows = [];

        foreach ($table->getRows() as $row) {
            $rows[] = array_map(
                fn ($cell): string => $this->textOf($cell),
                $row->getCells(),
            );
        }

        if ($rows === []) {
            return '';
        }

        $lines = [$this->tableLine($rows[0]), '|'.str_repeat(' --- |', count($rows[0]))];

        foreach (array_slice($rows, 1) as $row) {
            $lines[] = $this->tableLine($row);
        }

        return implode("\n", $lines);
    }

    private function tableLine(array $cells): string
    {
        return '| '.implode(' | ', array_map(fn (string $cell): string => str_replace('|', '\\|', $cell), $cells)).' |';
    }

    private function textOf(mixed $node): string
    {
        if (is_string($node)) {
            return trim($node);
        }

        if ($node instanceof Text) {
            return trim((string) $node->getText());
        }

        if ($node instanceof Link) {
            return trim((string) $node->getText());
        }

        if (! method_exists($node, 'getElements')) {
            return '';
        }

        $parts = [];

        foreach ($node->getElements() as $child) {
            $parts[] = $this->textOf($child);
        }

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', array_filter($parts, fn (string $part): bool => $part !== ''))));
    }
}
