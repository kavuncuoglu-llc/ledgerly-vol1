<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class InvoiceReadCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_handle_sends_the_image_as_a_data_url_to_the_vision_model_with_the_deterministic_profile(): void
    {
        Http::fake(['*' => Http::response($this->completion($this->reply(total: 1284.50)))]);

        $this->artisan('invoice:read', ['path' => storage_path('samples/phone-photo.jpg')])->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            $user = $request['messages'][1];

            return $request['model'] === 'llama3.2-vision:11b'
                && $request['temperature'] === 0
                && $request['seed'] === 42
                && $request['messages'][0]['role'] === 'system'
                && $user['content'][0]['type'] === 'text'
                && $user['content'][1]['type'] === 'image_url'
                && str_starts_with($user['content'][1]['image_url']['url'], 'data:image/jpeg;base64,');
        });
    }

    public function test_handle_prints_the_fields_and_confirms_agreeing_totals(): void
    {
        Http::fake(['*' => Http::response($this->completion($this->reply(total: 1284.50)))]);

        $this->artisan('invoice:read', ['path' => storage_path('samples/phone-photo.jpg')])
            ->expectsTable(
                ['field', 'value'],
                [
                    ['vendor', 'Northgate Paper Co'],
                    ['invoice #', 'NP-20931'],
                    ['due', '2026-04-15'],
                    ['total (model)', '1,284.50'],
                    ['total (lines)', '1,284.50'],
                    ['agree?', 'yes'],
                ],
            )
            ->expectsOutputToContain('in 742 out 118')
            ->assertSuccessful();
    }

    public function test_handle_flags_a_total_that_disagrees_with_the_line_items(): void
    {
        Http::fake(['*' => Http::response($this->completion($this->reply(total: 1204.50)))]);

        $this->artisan('invoice:read', ['path' => storage_path('samples/bad-scan.jpg')])
            ->expectsTable(
                ['field', 'value'],
                [
                    ['vendor', 'Northgate Paper Co'],
                    ['invoice #', 'NP-20931'],
                    ['due', '2026-04-15'],
                    ['total (model)', '1,204.50'],
                    ['total (lines)', '1,284.50'],
                    ['agree?', 'NO --- review'],
                ],
            )
            ->assertSuccessful();
    }

    public function test_handle_reads_a_json_reply_wrapped_in_a_code_fence(): void
    {
        Http::fake(['*' => Http::response($this->completion("```json\n".$this->reply(total: 1284.50)."\n```"))]);

        $this->artisan('invoice:read', ['path' => storage_path('samples/phone-photo.jpg')])
            ->expectsOutputToContain('Northgate Paper Co')
            ->assertSuccessful();
    }

    public function test_handle_reads_a_pdf_by_rendering_its_first_page(): void
    {
        if (trim((string) shell_exec('command -v pdftoppm')) === '') {
            $this->markTestSkipped('pdftoppm (Poppler) is not installed.');
        }

        Http::fake(['*' => Http::response($this->completion($this->reply(total: 1284.50)))]);

        $this->artisan('invoice:read', ['path' => storage_path('samples/clean.pdf')])->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_starts_with($request['messages'][1]['content'][1]['image_url']['url'], 'data:image/jpeg;base64,'));
    }

    public function test_handle_fails_for_a_missing_file_without_calling_the_model(): void
    {
        Http::fake();

        $this->artisan('invoice:read', ['path' => storage_path('samples/nope.pdf')])
            ->expectsOutputToContain('No file at')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_handle_fails_when_the_reply_has_no_json(): void
    {
        Http::fake(['*' => Http::response($this->completion('I cannot read this image.'))]);

        $this->artisan('invoice:read', ['path' => storage_path('samples/phone-photo.jpg')])
            ->expectsOutputToContain('no JSON object')
            ->assertFailed();
    }

    public function test_handle_reports_a_model_server_error_and_fails(): void
    {
        Http::fake(['*' => Http::response('model not found', 404)]);

        $this->artisan('invoice:read', ['path' => storage_path('samples/phone-photo.jpg')])
            ->expectsOutputToContain('HTTP 404')
            ->assertFailed();
    }

    public function test_sample_invoices_ship_with_the_app(): void
    {
        foreach (['clean.pdf', 'phone-photo.jpg', 'bad-scan.jpg'] as $sample) {
            $this->assertFileExists(storage_path("samples/{$sample}"));
        }
    }

    private function reply(float $total): string
    {
        return json_encode([
            'vendor_name' => 'Northgate Paper Co',
            'invoice_number' => 'NP-20931',
            'invoice_date' => '2026-03-16',
            'due_date' => '2026-04-15',
            'currency' => 'USD',
            'line_items' => [
                ['description' => 'A4 copy paper', 'quantity' => 40, 'unit_price' => 14.50, 'amount' => 580.00],
                ['description' => 'Toner cartridge', 'quantity' => 3, 'unit_price' => 89.50, 'amount' => 268.50],
                ['description' => 'Ring binders', 'quantity' => 120, 'unit_price' => 2.50, 'amount' => 300.00],
                ['description' => 'Delivery', 'quantity' => 1, 'unit_price' => 136.00, 'amount' => 136.00],
            ],
            'subtotal' => 1284.50,
            'tax' => 0,
            'total' => $total,
        ]);
    }

    private function completion(string $content): array
    {
        return [
            'choices' => [
                [
                    'message' => [
                        'content' => $content,
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 742,
                'completion_tokens' => 118,
            ],
        ];
    }
}
