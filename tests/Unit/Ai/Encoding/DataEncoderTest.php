<?php

namespace Tests\Unit\Ai\Encoding;

use App\Ai\Encoding\DataEncoder;
use App\Ai\Encoding\Format;
use App\Ai\Encoding\Formats\JsonFormat;
use App\Ai\Encoding\Formats\ToonFormat;
use App\Ai\Encoding\Formats\TsvFormat;
use App\Ai\Encoding\Shape;
use App\Ai\Exceptions\UnknownEncodingFormat;
use Illuminate\Support\Collection;
use Tests\TestCase;

class DataEncoderTest extends TestCase
{
    public function test_encode_applies_the_shape_fields_and_leaves_other_attributes_out(): void
    {
        $rows = collect([
            ['number' => 'INV-1', 'total' => 10.5, 'internal_flag' => 'secret'],
        ]);

        $text = $this->encoder()->encode($rows, $this->shape(), 'tsv');

        $this->assertStringContainsString("number\ttotal", $text);
        $this->assertStringContainsString("INV-1\t10.5", $text);
        $this->assertStringNotContainsString('secret', $text);
        $this->assertStringNotContainsString('internal_flag', $text);
    }

    public function test_encode_puts_the_shape_header_then_the_format_description_then_the_data(): void
    {
        $rows = collect([
            ['number' => 'INV-1', 'total' => 10.5],
        ]);

        $lines = explode("\n", $this->encoder()->encode($rows, $this->shape(header: 'Amounts in USD.'), 'tsv'));

        $this->assertSame('Amounts in USD.', $lines[0]);
        $this->assertStringContainsString('tab-separated', $lines[1]);
        $this->assertSame("number\ttotal", $lines[2]);
    }

    public function test_encode_enforces_the_limit_and_says_how_many_rows_were_cut(): void
    {
        $rows = collect([
            ['number' => 'INV-1', 'total' => 1],
            ['number' => 'INV-2', 'total' => 2],
            ['number' => 'INV-3', 'total' => 3],
        ]);

        $text = $this->encoder()->encode($rows, $this->shape(limit: 2), 'tsv');

        $this->assertStringContainsString('Showing 2 of 3 rows.', $text);
        $this->assertStringContainsString('INV-2', $text);
        $this->assertStringNotContainsString('INV-3', $text);
    }

    public function test_encode_does_not_mention_truncation_when_every_row_fits(): void
    {
        $rows = collect([
            ['number' => 'INV-1', 'total' => 1],
        ]);

        $text = $this->encoder()->encode($rows, $this->shape(limit: 5), 'tsv');

        $this->assertStringNotContainsString('Showing', $text);
    }

    public function test_encode_uses_the_configured_default_format_when_none_is_given(): void
    {
        config()->set('ai.encoding.default', 'json');
        $rows = collect([
            ['number' => 'INV-1', 'total' => 1],
        ]);

        $text = $this->encoder()->encode($rows, $this->shape(), key: 'invoices');

        $this->assertStringContainsString('{"invoices":[{"number":"INV-1","total":1}]}', $text);
    }

    public function test_encode_falls_back_to_toon_when_nothing_is_configured(): void
    {
        config()->set('ai.encoding.default', null);
        $rows = collect([
            ['number' => 'INV-1', 'total' => 1],
        ]);

        $text = $this->encoder()->encode($rows, $this->shape(), key: 'invoices');

        $this->assertStringContainsString('invoices[1]{number,total}:', $text);
    }

    public function test_encode_rejects_an_unknown_format_and_lists_the_known_ones(): void
    {
        $this->expectException(UnknownEncodingFormat::class);
        $this->expectExceptionMessage('Available: toon, tsv, json.');

        $this->encoder()->encode(collect(), $this->shape(), 'xml');
    }

    public function test_encode_hands_the_key_to_the_format(): void
    {
        $format = new class implements Format
        {
            public ?string $receivedKey = null;

            public function describe(): string
            {
                return 'Custom.';
            }

            public function write(array $rows, string $key): string
            {
                $this->receivedKey = $key;

                return 'body';
            }
        };

        (new DataEncoder(['custom' => $format]))->encode(collect(), $this->shape(), 'custom', 'widgets');

        $this->assertSame('widgets', $format->receivedKey);
    }

    private function encoder(): DataEncoder
    {
        return new DataEncoder([
            'toon' => new ToonFormat,
            'tsv' => new TsvFormat,
            'json' => new JsonFormat,
        ]);
    }

    private function shape(?string $header = null, int $limit = 25): Shape
    {
        return new class($header, $limit) implements Shape
        {
            public function __construct(
                private readonly ?string $header,
                private readonly int $limit,
            ) {}

            public function fields(): array
            {
                return [
                    'number' => fn (array $row): string => $row['number'],
                    'total' => fn (array $row): float|int => $row['total'],
                ];
            }

            public function header(Collection $rows): ?string
            {
                return $this->header;
            }

            public function limit(): int
            {
                return $this->limit;
            }
        };
    }
}
