<?php

namespace Tests\Unit\Ai\Parsing;

use App\Ai\Exceptions\UnsupportedDocument;
use App\Ai\Parsing\DocxParser;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

class DocxParserTest extends TestCase
{
    public function test_markdown_converts_heading_styles_paragraphs_lists_and_tables(): void
    {
        $path = $this->documentWith(function (PhpWord $word): void {
            $word->addTitleStyle(1, ['bold' => true]);
            $word->addTitleStyle(2, ['bold' => true]);
            $section = $word->addSection();
            $section->addTitle('Understanding late fees', 1);
            $section->addText('Overview paragraph.');
            $section->addTitle('Plans', 2);
            $section->addListItem('Starter');
            $section->addListItem('Pro');
            $table = $section->addTable();
            $table->addRow();
            $table->addCell(2000)->addText('Plan');
            $table->addCell(2000)->addText('Late fee');
            $table->addRow();
            $table->addCell(2000)->addText('Starter');
            $table->addCell(2000)->addText('1.5%');
        });

        $markdown = (new DocxParser)->markdown($path);

        $this->assertStringContainsString("# Understanding late fees\n\nOverview paragraph.", $markdown);
        $this->assertStringContainsString("## Plans\n\n- Starter\n\n- Pro", $markdown);
        $this->assertStringContainsString("| Plan | Late fee |\n| --- | --- |\n| Starter | 1.5% |", $markdown);
    }

    public function test_markdown_reads_paragraph_style_headings(): void
    {
        $path = $this->documentWith(function (PhpWord $word): void {
            $word->addParagraphStyle('Heading2', []);
            $section = $word->addSection();
            $section->addText('Proration', null, 'Heading2');
            $section->addText('Daily rate.');
        });

        $markdown = (new DocxParser)->markdown($path);

        $this->assertStringContainsString('Proration', $markdown);
        $this->assertStringContainsString('Daily rate.', $markdown);
    }

    public function test_markdown_fails_for_a_file_that_is_not_a_docx(): void
    {
        $path = sys_get_temp_dir().'/broken-'.uniqid().'.docx';
        File::put($path, 'not a zip');
        $this->beforeApplicationDestroyed(fn () => File::delete($path));

        $this->expectException(UnsupportedDocument::class);

        (new DocxParser)->markdown($path);
    }

    private function documentWith(callable $build): string
    {
        $word = new PhpWord;

        $build($word);

        $path = sys_get_temp_dir().'/doc-'.uniqid().'.docx';

        IOFactory::createWriter($word, 'Word2007')->save($path);

        $this->beforeApplicationDestroyed(fn () => File::delete($path));

        return $path;
    }
}
