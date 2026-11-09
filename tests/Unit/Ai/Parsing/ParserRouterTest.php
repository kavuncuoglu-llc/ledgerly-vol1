<?php

namespace Tests\Unit\Ai\Parsing;

use App\Ai\Exceptions\SidecarFailed;
use App\Ai\Exceptions\UnsupportedDocument;
use App\Ai\Parsing\DocxParser;
use App\Ai\Parsing\HtmlParser;
use App\Ai\Parsing\ImportedDocuments;
use App\Ai\Parsing\MarkdownParser;
use App\Ai\Parsing\ParserRouter;
use App\Ai\Parsing\SidecarPdfParser;
use App\Ai\Sidecar\SidecarClient;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ParserRouterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/parser-router-'.uniqid();

        File::makeDirectory($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_markdown_files_are_read_directly(): void
    {
        File::put("{$this->directory}/a.md", "# A\n\nText.");

        $this->assertSame("# A\n\nText.", $this->router()->markdown("{$this->directory}/a.md"));
    }

    public function test_html_files_go_through_the_html_parser(): void
    {
        File::put("{$this->directory}/a.html", '<html><body><main><h1>A</h1><p>Text.</p></main></body></html>');

        $this->assertStringContainsString('# A', $this->router()->markdown("{$this->directory}/a.html"));
    }

    public function test_pdf_files_go_to_the_sidecar(): void
    {
        Http::fake(['*/parse' => Http::response(['markdown' => '# From Marker'])]);
        File::put("{$this->directory}/a.pdf", '%PDF-1.4');

        $this->assertSame('# From Marker', $this->router()->markdown("{$this->directory}/a.PDF"));
    }

    public function test_an_unknown_extension_is_refused(): void
    {
        $this->expectException(UnsupportedDocument::class);

        $this->router()->markdown("{$this->directory}/a.exe");
    }

    public function test_supports_matches_the_registered_extensions_case_insensitively(): void
    {
        $this->assertTrue($this->router()->supports('/x/Manual.PDF'));
        $this->assertTrue($this->router()->supports('/x/page.htm'));
        $this->assertFalse($this->router()->supports('/x/archive.zip'));
    }

    public function test_imported_documents_become_articles_in_the_imports_category(): void
    {
        File::put("{$this->directory}/Late Fees Policy.md", "# Late fee policy\n\n## Rate\n\nOne and a half percent.");
        File::put("{$this->directory}/notes.html", '<html><body><h2>Section</h2><p>No h1 here.</p></body></html>');
        File::put("{$this->directory}/ignored.zip", 'x');

        $articles = (new ImportedDocuments($this->router()))->articlesIn($this->directory);

        $this->assertSame(['late-fees-policy', 'notes'], $articles->pluck('slug')->all());
        $this->assertSame('Late fee policy', $articles[0]->title);
        $this->assertSame('Notes', $articles[1]->title);
        $this->assertSame('help/imports/late-fees-policy.md', $articles[0]->path());
        $this->assertSame(['area' => 'imports'], $articles[0]->tags);
    }

    public function test_an_unreachable_sidecar_surfaces_as_a_sidecar_failure(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);
        File::put("{$this->directory}/a.pdf", '%PDF-1.4');

        $this->expectException(SidecarFailed::class);

        $this->router()->markdown("{$this->directory}/a.pdf");
    }

    private function router(): ParserRouter
    {
        return new ParserRouter(
            new MarkdownParser,
            new HtmlParser,
            new DocxParser,
            new SidecarPdfParser(new SidecarClient('http://sidecar.test:8008', 5, 30, 25)),
        );
    }
}
