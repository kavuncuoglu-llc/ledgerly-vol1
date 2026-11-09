<?php

namespace Tests\Feature\Console;

use App\Ai\Exceptions\EnforcementNotApplied;
use App\Models\ModelCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesChatCompletions;
use Tests\TestCase;

class InvoiceReadCommandTest extends TestCase
{
    use FakesChatCompletions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_handle_sends_the_image_as_a_data_url_to_the_vision_model_with_the_invoice_schema(): void
    {
        Http::fake(['*' => Http::response($this->completion($this->invoiceJson()))]);

        $this->artisan('invoice:read', ['path' => storage_path('samples/phone-photo.jpg')])->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            $user = $request['messages'][1];

            return $request['model'] === 'llama3.2-vision:11b'
                && $request['temperature'] === 0
                && $request['seed'] === 42
                && $request['response_format']['json_schema']['name'] === 'invoice_data'
                && $request['messages'][0]['role'] === 'system'
                && $user['content'][0]['type'] === 'text'
                && $user['content'][1]['type'] === 'image_url'
                && str_starts_with($user['content'][1]['image_url']['url'], 'data:image/jpeg;base64,');
        });
    }

    public function test_handle_prints_the_fields_and_reports_no_repair_when_the_first_answer_is_valid(): void
    {
        Http::fake(['*' => Http::response($this->completion($this->invoiceJson()))]);

        $this->artisan('invoice:read', ['path' => storage_path('samples/phone-photo.jpg')])
            ->expectsTable(
                ['field', 'value'],
                [
                    ['vendor', 'Northgate Paper Co'],
                    ['invoice #', 'NP-20931'],
                    ['due', '2026-04-15'],
                    ['total', '1,284.50'],
                    ['lines', 4],
                    ['repaired', 'no'],
                ],
            )
            ->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_handle_repairs_a_misread_total_once_and_reports_it(): void
    {
        Http::fakeSequence()
            ->push($this->completion($this->invoiceJson(total: 1204.50)))
            ->push($this->completion($this->invoiceJson(total: 1284.50)));

        $this->artisan('invoice:read', ['path' => storage_path('samples/bad-scan.jpg')])
            ->expectsTable(
                ['field', 'value'],
                [
                    ['vendor', 'Northgate Paper Co'],
                    ['invoice #', 'NP-20931'],
                    ['due', '2026-04-15'],
                    ['total', '1,284.50'],
                    ['lines', 4],
                    ['repaired', 'yes'],
                ],
            )
            ->assertSuccessful();
    }

    public function test_handle_sends_the_validation_errors_back_in_the_repair_turn(): void
    {
        Http::fakeSequence()
            ->push($this->completion($this->invoiceJson(total: 1204.50)))
            ->push($this->completion($this->invoiceJson(total: 1284.50)));

        $this->artisan('invoice:read', ['path' => storage_path('samples/bad-scan.jpg')])->assertSuccessful();

        $requests = Http::recorded();

        $repairMessages = $requests[1][0]['messages'];

        $this->assertSame('assistant', $repairMessages[2]['role']);
        $this->assertSame('user', $repairMessages[3]['role']);
        $this->assertStringContainsString('Total 1204.50 does not equal 1284.50', $repairMessages[3]['content']);
    }

    public function test_handle_logs_both_attempts_and_marks_only_the_second_as_repaired(): void
    {
        Http::fakeSequence()
            ->push($this->completion($this->invoiceJson(total: 1204.50)))
            ->push($this->completion($this->invoiceJson(total: 1284.50)));

        $this->artisan('invoice:read', ['path' => storage_path('samples/bad-scan.jpg')])->assertSuccessful();

        $this->assertSame(
            [false, true],
            ModelCall::query()->where('feature', 'invoice.extract')->orderBy('id')->pluck('repaired')->all(),
        );
    }

    public function test_handle_fails_when_the_answer_is_still_invalid_after_one_repair(): void
    {
        Http::fake(['*' => Http::response($this->completion($this->invoiceJson(total: 1204.50)))]);

        $this->artisan('invoice:read', ['path' => storage_path('samples/bad-scan.jpg')])
            ->expectsOutputToContain('still invalid after one repair')
            ->assertFailed();

        Http::assertSentCount(2);
    }

    public function test_handle_fails_without_a_repair_when_the_model_declines_through_the_error_field(): void
    {
        Http::fake(['*' => Http::response($this->completion($this->invoiceJson(error: 'image too blurry to read totals')))]);

        $this->artisan('invoice:read', ['path' => storage_path('samples/bad-scan.jpg')])
            ->expectsOutputToContain('image too blurry to read totals')
            ->assertFailed();

        Http::assertSentCount(1);
    }

    public function test_handle_fails_when_the_reply_is_not_json_because_enforcement_is_off(): void
    {
        Http::fake(['*' => Http::response($this->completion('I cannot read this image.'))]);

        $this->expectException(EnforcementNotApplied::class);

        $this->artisan('invoice:read', ['path' => storage_path('samples/phone-photo.jpg')]);
    }

    public function test_handle_reads_a_pdf_by_rendering_its_first_page(): void
    {
        if (trim((string) shell_exec('command -v pdftoppm')) === '') {
            $this->markTestSkipped('pdftoppm (Poppler) is not installed.');
        }

        Http::fake(['*' => Http::response($this->completion($this->invoiceJson()))]);

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
}
