<?php

namespace Tests\Unit\Ai\Testing;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\EmbedKind;
use App\Ai\Contracts\FinishReason;
use App\Ai\Contracts\Stream\Completed;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Contracts\Stream\ToolCallArguments;
use App\Ai\Contracts\Stream\ToolCallStarted;
use App\Ai\Exceptions\InvalidFakeScript;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Exceptions\UnscriptedModelCall;
use App\Ai\Facades\Ai;
use App\Ai\Retrieval\LexicalReranker;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

class AiFakeTest extends TestCase
{
    public function test_a_scripted_reply_is_returned_with_a_stop_finish_reason(): void
    {
        Ai::fake()->for('ticket.classify')->respondWith('billing');

        $response = Ai::for('ticket.classify')->chat($this->request('ticket.classify'));

        $this->assertSame('billing', $response->content);
        $this->assertSame(FinishReason::Stop, $response->finishReason);
        $this->assertSame([], $response->toolCalls);
    }

    public function test_replies_are_consumed_in_order_per_feature(): void
    {
        Ai::fake()
            ->for('support.reply')->respondWith('first')
            ->for('ticket.classify')->respondWith('billing', times: 2)
            ->for('support.reply')->respondWith('second');

        $this->assertSame('first', Ai::for('support.reply')->chat($this->request('support.reply'))->content);
        $this->assertSame('billing', Ai::for('ticket.classify')->chat($this->request('ticket.classify'))->content);
        $this->assertSame('second', Ai::for('support.reply')->chat($this->request('support.reply'))->content);
        $this->assertSame('billing', Ai::for('ticket.classify')->chat($this->request('ticket.classify'))->content);
    }

    public function test_an_unscripted_call_fails_loudly_instead_of_guessing(): void
    {
        Ai::fake();

        $this->expectException(UnscriptedModelCall::class);
        $this->expectExceptionMessage('support.reply');

        Ai::for('support.reply')->chat($this->request('support.reply'));
    }

    public function test_a_reply_without_a_feature_answers_any_feature(): void
    {
        Ai::fake()->respondWith('anything');

        $this->assertSame('anything', Ai::chat('frontier')->chat($this->request('whatever'))->content);
    }

    public function test_a_json_reply_is_encoded_and_a_tool_call_reply_carries_the_call(): void
    {
        Ai::fake()
            ->for('ticket.extract')->respondWithJson(['invoice_number' => '4471'])
            ->for('support.reply')->respondWithToolCall('lookup_invoice', ['invoice_number' => '4471']);

        $json = Ai::for('ticket.extract')->chat($this->request('ticket.extract'));
        $tool = Ai::for('support.reply')->chat($this->request('support.reply'));

        $this->assertSame('{"invoice_number":"4471"}', $json->content);
        $this->assertSame(FinishReason::ToolCalls, $tool->finishReason);
        $this->assertNull($tool->content);
        $this->assertSame('lookup_invoice', $tool->toolCalls[0]['function']['name']);
        $this->assertSame('{"invoice_number":"4471"}', $tool->toolCalls[0]['function']['arguments']);
        $this->assertNotSame('', $tool->toolCalls[0]['id']);
    }

    public function test_a_tool_call_with_no_arguments_encodes_an_empty_object(): void
    {
        Ai::fake()->respondWithToolCall('account_status', []);

        $tool = Ai::chat()->chat($this->request('support.reply'));

        $this->assertSame('{}', $tool->toolCalls[0]['function']['arguments']);
    }

    public function test_a_streamed_reply_yields_each_delta_then_completed(): void
    {
        Ai::fake()->respondWithStream(['The', ' fee', ' is']);

        $stream = Ai::chat()->stream($this->request('support.reply'));
        $events = iterator_to_array($stream, false);

        $this->assertEquals([new Delta('The'), new Delta(' fee'), new Delta(' is')], array_slice($events, 0, 3));
        $this->assertInstanceOf(Completed::class, $events[3]);
        $this->assertSame('The fee is', $stream->getReturn()->content);
    }

    public function test_a_streamed_tool_call_announces_the_tool_then_its_arguments(): void
    {
        Ai::fake()->respondWithToolCall('lookup_invoice', ['invoice_number' => '4471']);

        $events = iterator_to_array(Ai::chat()->stream($this->request('support.reply')), false);

        $this->assertEquals(new ToolCallStarted(0, 'lookup_invoice'), $events[0]);
        $this->assertEquals(new ToolCallArguments(0, '{"invoice_number":"4471"}'), $events[1]);
    }

    public function test_a_scripted_failure_is_thrown(): void
    {
        Ai::fake()->failWith(new ProviderUnavailable('down'));

        $this->expectException(ProviderUnavailable::class);

        Ai::chat()->chat($this->request('support.reply'));
    }

    public function test_a_script_that_breaks_the_response_format_fails_the_way_production_would(): void
    {
        Ai::fake()->for('ticket.extract')->respondWith('{"category":"nonsense"}');

        $this->expectException(InvalidFakeScript::class);
        $this->expectExceptionMessage('category');

        Ai::for('ticket.extract')->chat($this->schemaRequest());
    }

    public function test_a_script_that_is_not_json_fails_a_response_format_request(): void
    {
        Ai::fake()->for('ticket.extract')->respondWith('billing');

        $this->expectException(InvalidFakeScript::class);

        Ai::for('ticket.extract')->chat($this->schemaRequest());
    }

    public function test_a_script_that_satisfies_the_schema_passes(): void
    {
        Ai::fake()->for('ticket.extract')->respondWithJson(['category' => 'billing']);

        $response = Ai::for('ticket.extract')->chat($this->schemaRequest());

        $this->assertSame('{"category":"billing"}', $response->content);
    }

    public function test_assert_sent_matches_on_the_request_and_the_provider(): void
    {
        Ai::fake()->respondWith('ok', times: 2);

        Ai::for('ticket.classify')->chat($this->request('ticket.classify'));
        Ai::chat('frontier')->chat($this->request('support.reply'));

        Ai::assertSent(fn (ChatRequest $request, string $provider): bool => $request->feature === 'ticket.classify' && $provider === 'local');
        Ai::assertSent(fn (ChatRequest $request, string $provider): bool => $provider === 'frontier');
        Ai::assertNotSent(fn (ChatRequest $request): bool => $request->feature === 'invoice.extract');
        Ai::assertSentCount('ticket.classify', 1);
        Ai::assertNothingSentTo('vision');
    }

    public function test_assert_nothing_sent_to_fails_when_the_provider_was_called(): void
    {
        Ai::fake()->respondWith('ok');
        Ai::chat('frontier')->chat($this->request('support.reply'));

        $this->expectException(AssertionFailedError::class);

        Ai::assertNothingSentTo('frontier');
    }

    public function test_assert_sent_fails_when_nothing_matches(): void
    {
        Ai::fake();

        $this->expectException(AssertionFailedError::class);

        Ai::assertSent(fn (ChatRequest $request): bool => true);
    }

    public function test_assert_nothing_sent_passes_for_an_idle_fake(): void
    {
        Ai::fake();

        Ai::assertNothingSent();
        Ai::assertSentCount('support.reply', 0);
    }

    public function test_the_fake_embeds_deterministically_and_reranks_lexically(): void
    {
        Ai::fake();

        $first = Ai::embed()->embed(['late fees'], EmbedKind::Query);
        $second = Ai::embed()->embed(['late fees'], EmbedKind::Document);

        $this->assertSame($first, $second);
        $this->assertCount(768, $first[0]);
        $this->assertInstanceOf(LexicalReranker::class, Ai::reranker());
    }

    public function test_the_fake_knows_which_providers_exist_so_escalation_can_be_exercised(): void
    {
        Ai::fake();

        $this->assertTrue(app(AiManager::class)->hasEscalation('support.reply'));
        $this->assertFalse(app(AiManager::class)->hasEscalation('ticket.classify'));
    }

    private function request(string $feature): ChatRequest
    {
        return ChatRequest::fromOptions([['role' => 'user', 'content' => 'Hi']], [], $feature);
    }

    private function schemaRequest(): ChatRequest
    {
        return ChatRequest::fromOptions([['role' => 'user', 'content' => 'Classify']], [
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'ticket_category',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => ['category' => ['type' => 'string', 'enum' => ['billing', 'account']]],
                        'required' => ['category'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ], 'ticket.extract');
    }
}
