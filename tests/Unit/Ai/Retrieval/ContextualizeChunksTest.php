<?php

namespace Tests\Unit\Ai\Retrieval;

use App\Ai\Context\ProviderCapabilities;
use App\Ai\Exceptions\ContextGenerationFailed;
use App\Ai\Http\AiHttpClient;
use App\Ai\Retrieval\ContextualizeChunks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\SeedsHelpChunks;
use Tests\TestCase;

class ContextualizeChunksTest extends TestCase
{
    use RefreshDatabase;
    use SeedsHelpChunks;

    public function test_context_for_returns_the_model_sentence_on_one_line(): void
    {
        Http::fake(['*' => Http::response($this->chatReply("This chunk is the proration rule\nin the late fee article. "))]);

        $context = $this->contextualizer()->contextFor('# Late fees', 'The daily rate is the monthly rate divided by 30.');

        $this->assertSame('This chunk is the proration rule in the late fee article.', $context);
    }

    public function test_context_for_puts_the_whole_article_in_the_system_message_and_the_chunk_in_the_user_message(): void
    {
        Http::fake(['*' => Http::response($this->chatReply('Context.'))]);

        $this->contextualizer()->contextFor('ARTICLE BODY', 'CHUNK TEXT');

        Http::assertSent(fn (Request $request): bool => $request['messages'][0]['role'] === 'system'
            && $request['messages'][0]['content'] === "<document>\nARTICLE BODY\n</document>"
            && str_contains($request['messages'][1]['content'], "<chunk>\nCHUNK TEXT\n</chunk>")
            && $request['max_tokens'] === 150
            && $request['temperature'] === 0
            && ! isset($request['messages'][0]['cache_control']));
    }

    public function test_context_for_marks_the_article_as_a_cache_breakpoint_on_providers_that_support_it(): void
    {
        Http::fake(['*' => Http::response($this->chatReply('Context.'))]);
        $capabilities = new ProviderCapabilities('anthropic');

        (new ContextualizeChunks(app(AiHttpClient::class), $capabilities))->contextFor('ARTICLE', 'CHUNK');

        Http::assertSent(fn (Request $request): bool => $request['messages'][0]['cache_control'] === ['type' => 'ephemeral']);
    }

    public function test_context_for_uses_the_configured_context_model(): void
    {
        Http::fake(['*' => Http::response($this->chatReply('Context.'))]);

        (new ContextualizeChunks(app(AiHttpClient::class), new ProviderCapabilities('ollama'), 'llama3.2:3b'))->contextFor('A', 'C');

        Http::assertSent(fn (Request $request): bool => $request['model'] === 'llama3.2:3b');
    }

    public function test_context_for_fails_when_the_model_returns_nothing(): void
    {
        Http::fake(['*' => Http::response($this->chatReply('   '))]);

        $this->expectException(ContextGenerationFailed::class);

        $this->contextualizer()->contextFor('A', 'C');
    }

    private function contextualizer(): ContextualizeChunks
    {
        return new ContextualizeChunks(app(AiHttpClient::class), new ProviderCapabilities('ollama'));
    }
}
