<?php

namespace Tests\Unit\Ai\Parsing;

use App\Ai\Parsing\HtmlParser;
use PHPUnit\Framework\TestCase;

class HtmlParserTest extends TestCase
{
    public function test_from_html_keeps_headings_lists_and_links_as_markdown(): void
    {
        $markdown = (new HtmlParser)->fromHtml('<html><body><main><h1>Late fees</h1><h2>Rate</h2><p>The fee is <strong>1.5%</strong>.</p><ul><li>Prorated</li><li>Per month</li></ul></main></body></html>');

        $this->assertStringContainsString('# Late fees', $markdown);
        $this->assertStringContainsString('## Rate', $markdown);
        $this->assertStringContainsString('**1.5%**', $markdown);
        $this->assertStringContainsString('- Prorated', $markdown);
    }

    public function test_from_html_drops_navigation_footer_scripts_and_everything_outside_main(): void
    {
        $html = '<html><body><nav>Home | Pricing</nav><header>Brand</header><main><h1>Title</h1><p>Body text.</p><script>alert(1)</script></main><footer>Copyright</footer></body></html>';

        $markdown = (new HtmlParser)->fromHtml($html);

        $this->assertStringContainsString('Body text.', $markdown);
        $this->assertStringNotContainsString('Pricing', $markdown);
        $this->assertStringNotContainsString('Brand', $markdown);
        $this->assertStringNotContainsString('alert', $markdown);
        $this->assertStringNotContainsString('Copyright', $markdown);
    }

    public function test_from_html_falls_back_to_the_body_without_a_content_element(): void
    {
        $markdown = (new HtmlParser)->fromHtml('<html><body><h2>Only a body</h2><p>Text.</p></body></html>');

        $this->assertStringContainsString('## Only a body', $markdown);
    }

    public function test_from_html_keeps_non_ascii_text(): void
    {
        $markdown = (new HtmlParser)->fromHtml('<html><body><p>Gebühren für Rechnungen</p></body></html>');

        $this->assertStringContainsString('Gebühren für Rechnungen', $markdown);
    }
}
