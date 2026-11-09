<?php

namespace Tests\Unit\Ai\Encoding;

use App\Ai\Encoding\Formats\JsonFormat;
use App\Ai\Encoding\Formats\MarkdownFormat;
use App\Ai\Encoding\Formats\ToonFormat;
use App\Ai\Encoding\Formats\TsvFormat;
use App\Ai\Encoding\Formats\YamlFormat;
use HelgeSverre\Toon\Toon;
use PHPUnit\Framework\TestCase;

class FormatsTest extends TestCase
{
    public function test_toon_writes_the_header_once_and_one_row_per_line(): void
    {
        $text = (new ToonFormat)->write($this->rows(), 'invoices');

        $this->assertStringContainsString('invoices[2]{number,status,total}:', $text);
        $this->assertStringContainsString('INV-4471,overdue,1284.5', $text);
        $this->assertSame(1, substr_count($text, 'number'));
    }

    public function test_toon_round_trips_through_the_parser(): void
    {
        $text = (new ToonFormat)->write($this->rows(), 'invoices');

        $this->assertSame(['invoices' => $this->rows()], Toon::decode($text));
    }

    public function test_toon_quotes_a_value_that_contains_the_delimiter(): void
    {
        $rows = [
            ['text' => 'Paid late, waive fee once'],
        ];

        $text = (new ToonFormat)->write($rows, 'notes');

        $this->assertStringContainsString('"Paid late, waive fee once"', $text);
        $this->assertSame(['notes' => $rows], Toon::decode($text));
    }

    public function test_toon_can_use_a_tab_delimiter_and_says_so(): void
    {
        $format = new ToonFormat("\t");

        $this->assertStringContainsString("INV-4471\toverdue\t1284.5", $format->write($this->rows(), 'invoices'));
        $this->assertStringContainsString('tab-separated', $format->describe());
    }

    public function test_tsv_writes_field_names_once_then_tab_separated_rows(): void
    {
        $text = (new TsvFormat)->write($this->rows(), 'invoices');

        $this->assertSame("number\tstatus\ttotal\nINV-4471\toverdue\t1284.5\nINV-4472\tpaid\t640", $text);
    }

    public function test_tsv_flattens_tabs_and_newlines_inside_a_value(): void
    {
        $rows = [
            ['note' => "two\tlines\nhere", 'flag' => true, 'gap' => null],
        ];

        $text = (new TsvFormat)->write($rows, 'rows');

        $this->assertSame("note\tflag\tgap\ntwo lines here\ttrue\t", $text);
    }

    public function test_tsv_of_no_rows_is_empty(): void
    {
        $this->assertSame('', (new TsvFormat)->write([], 'rows'));
    }

    public function test_markdown_writes_an_unaligned_table(): void
    {
        $text = (new MarkdownFormat)->write($this->rows(), 'invoices');

        $this->assertSame("| number | status | total |\n| --- | --- | --- |\n| INV-4471 | overdue | 1284.5 |\n| INV-4472 | paid | 640 |", $text);
    }

    public function test_markdown_escapes_a_pipe_inside_a_cell(): void
    {
        $text = (new MarkdownFormat)->write([['note' => 'a|b']], 'rows');

        $this->assertStringContainsString('| a\\|b |', $text);
    }

    public function test_json_is_compact_by_default_and_pretty_on_request(): void
    {
        $compact = (new JsonFormat)->write($this->rows(), 'invoices');
        $pretty = (new JsonFormat(pretty: true))->write($this->rows(), 'invoices');

        $this->assertStringStartsWith('{"invoices":[{"number":"INV-4471"', $compact);
        $this->assertStringContainsString("\n", $pretty);
        $this->assertSame(json_decode($compact, true), json_decode($pretty, true));
    }

    public function test_yaml_writes_a_list_of_mappings(): void
    {
        $text = (new YamlFormat)->write($this->rows(), 'invoices');

        $this->assertSame("invoices:\n  - number: INV-4471\n    status: overdue\n    total: 1284.5\n  - number: INV-4472\n    status: paid\n    total: 640", $text);
    }

    public function test_yaml_quotes_values_that_would_change_type_or_break_the_grammar(): void
    {
        $rows = [
            ['a' => 'no', 'b' => '2026', 'c' => 'x: y', 'd' => '', 'e' => null],
        ];

        $text = (new YamlFormat)->write($rows, 'rows');

        $this->assertStringContainsString('a: "no"', $text);
        $this->assertStringContainsString('b: "2026"', $text);
        $this->assertStringContainsString('c: "x: y"', $text);
        $this->assertStringContainsString('d: ""', $text);
        $this->assertStringContainsString('e: null', $text);
    }

    public function test_every_format_describes_itself_in_one_line(): void
    {
        foreach ([new ToonFormat, new TsvFormat, new MarkdownFormat, new JsonFormat, new YamlFormat] as $format) {
            $this->assertNotSame('', $format->describe());
            $this->assertStringNotContainsString("\n", $format->describe());
        }
    }

    private function rows(): array
    {
        return [
            ['number' => 'INV-4471', 'status' => 'overdue', 'total' => 1284.5],
            ['number' => 'INV-4472', 'status' => 'paid', 'total' => 640],
        ];
    }
}
