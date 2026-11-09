<?php

namespace Tests\Unit\Ai\Support;

use App\Ai\Events\ModelCalled;
use App\Ai\Prompts\TicketClassifyPrompt;
use App\Ai\Support\ClassifyTicket;
use App\Enums\TicketCategory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesChatCompletions;
use Tests\TestCase;
use ValueError;

class ClassifyTicketGrammarTest extends TestCase
{
    use FakesChatCompletions;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Event::fake([ModelCalled::class]);

        config()->set('ai.providers.llama-test', [
            'driver' => 'llama-server',
            'base_url' => 'http://llama.test:8080/v1',
            'api_key' => 'secret-key',
            'model' => 'ledgerly-support',
        ]);
    }

    public function test_a_llama_server_classifier_is_constrained_to_the_five_labels(): void
    {
        $this->useLlamaServer();
        $this->fakeLabel('billing');

        $category = $this->classify('Why was I charged a late fee?');

        $this->assertSame(TicketCategory::Billing, $category);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/completion')
            && $request['grammar'] === 'root ::= "billing" | "howto" | "bug" | "account" | "other"'
            && $request['n_predict'] === TicketClassifyPrompt::GRAMMAR_MAX_TOKENS
            && $request['temperature'] === 0
            && $request['seed'] === 42
            && ! isset($request['response_format'])
            && ! isset($request['json_schema']));
    }

    public function test_the_prompt_is_the_same_few_shot_conversation_rendered_by_the_server(): void
    {
        $this->useLlamaServer();
        $this->fakeLabel('bug');

        $this->classify('The PDF export button does nothing');

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/apply-template')
            && $request['messages'] === TicketClassifyPrompt::messages('The PDF export button does nothing'));
    }

    public function test_an_off_topic_message_gets_the_other_label_rather_than_an_invalid_one(): void
    {
        $this->useLlamaServer();
        $this->fakeLabel('other');

        $category = $this->classify('Can you recommend an accountant?');

        $this->assertSame(TicketCategory::Other, $category);
    }

    public function test_the_label_is_trimmed_before_it_becomes_a_category(): void
    {
        $this->useLlamaServer();
        $this->fakeLabel(" howto\n");

        $this->assertSame(TicketCategory::Howto, $this->classify('How do I add a team member?'));
    }

    public function test_a_server_that_ignored_the_grammar_is_an_error_not_a_guess(): void
    {
        $this->useLlamaServer();
        $this->fakeLabel('I think this is a billing question');

        $this->expectException(ValueError::class);

        $this->classify('Why was I charged?');
    }

    public function test_caller_options_such_as_a_sampling_temperature_still_reach_the_server(): void
    {
        $this->useLlamaServer();
        $this->fakeLabel('account');

        app(ClassifyTicket::class)('I cannot log in', ['temperature' => 0.7, 'seed' => 7]);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/completion')
            && $request['temperature'] === 0.7
            && $request['seed'] === 7);
    }

    public function test_the_grammar_call_is_logged_under_the_classify_feature_with_the_prompt_version(): void
    {
        $this->useLlamaServer();
        $this->fakeLabel('bug');

        $this->classify('Export is broken');

        Event::assertDispatched(ModelCalled::class, fn (ModelCalled $event): bool => $event->feature === 'ticket.classify'
            && $event->provider === 'llama-test'
            && $event->promptVersion === TicketClassifyPrompt::VERSION);
    }

    public function test_an_ollama_classifier_still_uses_the_schema_route(): void
    {
        Http::fake(['*' => Http::response($this->completion($this->ticketJson('billing')))]);

        $category = $this->classify('My card was charged twice');

        $this->assertSame(TicketCategory::Billing, $category);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/apply-template'));
        Http::assertSent(fn (Request $request): bool => $request['response_format']['type'] === 'json_schema');
    }

    private function useLlamaServer(): void
    {
        config()->set('ai.features', [
            ...config('ai.features'),
            'ticket.classify' => [
                'provider' => 'llama-test',
                'profile' => 'deterministic',
            ],
        ]);
    }

    private function fakeLabel(string $label): void
    {
        Http::fake([
            '*/apply-template' => Http::response(['prompt' => 'rendered']),
            '*/completion' => Http::response([
                'content' => $label,
                'stop_type' => 'eos',
                'tokens_evaluated' => 300,
                'tokens_predicted' => 2,
                'tokens_cached' => 280,
            ]),
        ]);
    }

    private function classify(string $message): TicketCategory
    {
        return app(ClassifyTicket::class)($message);
    }
}
