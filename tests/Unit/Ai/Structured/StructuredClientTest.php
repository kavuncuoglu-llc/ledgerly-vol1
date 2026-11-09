<?php

namespace Tests\Unit\Ai\Structured;

use App\Ai\Events\ModelCalled;
use App\Ai\Exceptions\EnforcementNotApplied;
use App\Ai\Exceptions\StructuredOutputFailed;
use App\Ai\Exceptions\StructuredRefusal;
use App\Ai\Schemas\InvoiceData;
use App\Ai\Schemas\TicketData;
use App\Ai\Structured\StructuredClient;
use App\Enums\TicketCategory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesChatCompletions;
use Tests\TestCase;

class StructuredClientTest extends TestCase
{
    use FakesChatCompletions;

    private const MESSAGES = [
        [
            'role' => 'user',
            'content' => 'Extract this',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Event::fake([ModelCalled::class]);
    }

    public function test_extract_sends_the_schema_as_a_strict_json_schema_response_format(): void
    {
        Http::fake(['*' => Http::response($this->completion($this->ticketJson('bug')))]);

        $this->client()->extract(TicketData::class, self::MESSAGES);

        Http::assertSent(function (Request $request): bool {
            $format = $request['response_format'];

            return $format['type'] === 'json_schema'
                && $format['json_schema']['name'] === 'ticket_data'
                && $format['json_schema']['strict'] === true
                && $format['json_schema']['schema']['additionalProperties'] === false
                && $request['temperature'] === 0
                && $request['seed'] === 42
                && $request['messages'] === self::MESSAGES;
        });
    }

    public function test_extract_lets_the_caller_override_the_profile_and_model(): void
    {
        Http::fake(['*' => Http::response($this->completion($this->ticketJson('bug')))]);

        $this->client()->extract(TicketData::class, self::MESSAGES, ['profile' => 'grounded', 'model' => 'other-model']);

        Http::assertSent(fn (Request $request): bool => $request['model'] === 'other-model'
            && $request['temperature'] === 0.2);
    }

    public function test_extract_returns_a_typed_object_with_meta_when_the_first_answer_is_valid(): void
    {
        Http::fake(['*' => Http::response($this->completion($this->ticketJson('billing', '4471', 'acme@example.com', 1284.5)))]);

        $ticket = $this->client()->extract(TicketData::class, self::MESSAGES);

        $this->assertInstanceOf(TicketData::class, $ticket);
        $this->assertSame(TicketCategory::Billing, $ticket->category);
        $this->assertSame('4471', $ticket->invoice_number);
        $this->assertSame(1284.5, $ticket->amount_mentioned);
        $this->assertNull($ticket->date_mentioned);
        $this->assertFalse($ticket->meta->repaired);
        $this->assertSame(1, $ticket->meta->attempts);
        Http::assertSentCount(1);
    }

    public function test_extract_sends_one_repair_turn_with_the_invalid_output_and_the_errors(): void
    {
        $invalid = $this->ticketJson('billing', dateMentioned: '03/04/2026');

        Http::fakeSequence()
            ->push($this->completion($invalid))
            ->push($this->completion($this->ticketJson('billing')));

        $ticket = $this->client()->extract(TicketData::class, self::MESSAGES);

        $this->assertTrue($ticket->meta->repaired);
        $this->assertSame(2, $ticket->meta->attempts);

        $repairRequest = Http::recorded()[1][0];

        $this->assertSame(self::MESSAGES[0], $repairRequest['messages'][0]);
        $this->assertSame(['role' => 'assistant', 'content' => $invalid], $repairRequest['messages'][1]);
        $this->assertSame('user', $repairRequest['messages'][2]['role']);
        $this->assertStringContainsString('The object you returned has validation errors', $repairRequest['messages'][2]['content']);
        $this->assertStringContainsString('date_mentioned', $repairRequest['messages'][2]['content']);
        $this->assertStringContainsString('If a value cannot be determined from the source, use null.', $repairRequest['messages'][2]['content']);
    }

    public function test_extract_keeps_the_same_schema_and_profile_on_the_repair_request(): void
    {
        Http::fakeSequence()
            ->push($this->completion($this->ticketJson('nope')))
            ->push($this->completion($this->ticketJson('bug')));

        $this->client()->extract(TicketData::class, self::MESSAGES);

        [$first, $second] = [Http::recorded()[0][0], Http::recorded()[1][0]];

        $this->assertSame($first['response_format'], $second['response_format']);
        $this->assertSame($first['temperature'], $second['temperature']);
    }

    public function test_extract_logs_each_attempt_and_marks_only_the_repair(): void
    {
        Http::fakeSequence()
            ->push($this->completion($this->ticketJson('nope')))
            ->push($this->completion($this->ticketJson('bug')));

        $this->client()->extract(TicketData::class, self::MESSAGES, feature: 'ticket.extract', promptVersion: 'v1');

        $logged = [];

        Event::assertDispatched(ModelCalled::class, function (ModelCalled $event) use (&$logged): bool {
            $logged[] = [$event->feature, $event->promptVersion, $event->repaired];

            return true;
        });

        $this->assertSame([['ticket.extract', 'v1', false], ['ticket.extract', 'v1', true]], $logged);
    }

    public function test_extract_throws_with_both_attempts_and_the_errors_when_the_repair_is_invalid_too(): void
    {
        Http::fake(['*' => Http::response($this->completion($this->ticketJson('nope')))]);

        try {
            $this->client()->extract(TicketData::class, self::MESSAGES);

            $this->fail('Expected StructuredOutputFailed.');
        } catch (StructuredOutputFailed $exception) {
            $this->assertSame(TicketData::class, $exception->class);
            $this->assertCount(2, $exception->attempts);
            $this->assertTrue($exception->errors->has('category'));
        }

        Http::assertSentCount(2);
    }

    public function test_extract_never_makes_a_second_repair_attempt(): void
    {
        Http::fake(['*' => Http::response($this->completion($this->ticketJson('nope')))]);

        $this->expectException(StructuredOutputFailed::class);

        try {
            $this->client()->extract(TicketData::class, self::MESSAGES);
        } finally {
            Http::assertSentCount(2);
        }
    }

    public function test_extract_throws_a_refusal_without_repairing_when_the_error_field_is_set(): void
    {
        Http::fake(['*' => Http::response($this->completion($this->invoiceJson(error: 'image too blurry to read totals')))]);

        try {
            $this->client()->extract(InvoiceData::class, self::MESSAGES);

            $this->fail('Expected StructuredRefusal.');
        } catch (StructuredRefusal $exception) {
            $this->assertSame('image too blurry to read totals', $exception->reason);
            $this->assertCount(1, $exception->attempts);
        }

        Http::assertSentCount(1);
    }

    public function test_extract_throws_when_the_reply_is_not_json_because_enforcement_is_off(): void
    {
        Http::fake(['*' => Http::response($this->completion("```json\n{}\n```"))]);

        $this->expectException(EnforcementNotApplied::class);

        $this->client()->extract(InvoiceData::class, self::MESSAGES);
    }

    public function test_extract_throws_when_the_reply_is_json_but_not_an_object(): void
    {
        Http::fake(['*' => Http::response($this->completion('"billing"'))]);

        $this->expectException(EnforcementNotApplied::class);

        $this->client()->extract(TicketData::class, self::MESSAGES);
    }

    public function test_extract_passes_source_text_to_the_after_rules_for_provenance(): void
    {
        Http::fakeSequence()
            ->push($this->completion($this->invoiceJson(total: 1284.50)))
            ->push($this->completion($this->invoiceJson(total: 1284.50)));

        $this->expectException(StructuredOutputFailed::class);

        $this->client()->extract(InvoiceData::class, self::MESSAGES, sourceText: 'Total 999.00');
    }

    private function client(): StructuredClient
    {
        return app(StructuredClient::class);
    }
}
