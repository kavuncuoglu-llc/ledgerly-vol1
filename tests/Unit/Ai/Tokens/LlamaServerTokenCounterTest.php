<?php

namespace Tests\Unit\Ai\Tokens;

use App\Ai\Exceptions\InvalidRequest;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Tokens\LlamaServerTokenCounter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LlamaServerTokenCounterTest extends TestCase
{
    public function test_the_count_is_the_length_of_the_tokens_the_server_returns(): void
    {
        Http::fake(['*/tokenize' => Http::response(['tokens' => [36120, 674, 20213, 16, 374, 83893, 555, 220, 2919, 13]])]);

        $count = $this->counter()->count('Invoice #4471 is overdue by 30 days.');

        $this->assertSame(10, $count);
    }

    public function test_the_text_goes_to_the_native_tokenize_endpoint_with_the_key(): void
    {
        Http::fake(['*/tokenize' => Http::response(['tokens' => [1]])]);

        $this->counter()->count('Hello');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://llama.test:8080/tokenize'
            && $request->header('Authorization') === ['Bearer secret-key']
            && $request['content'] === 'Hello');
    }

    public function test_the_same_text_is_only_tokenized_once(): void
    {
        Http::fake(['*/tokenize' => Http::response(['tokens' => [1, 2, 3]])]);

        $counter = $this->counter();

        $counter->count('Hello');
        $counter->count('Hello');
        $counter->count('Goodbye');

        Http::assertSentCount(2);
    }

    public function test_empty_text_costs_nothing_and_asks_nothing(): void
    {
        Http::fake();

        $this->assertSame(0, $this->counter()->count(''));

        Http::assertNothingSent();
    }

    public function test_an_answer_without_tokens_is_a_malformed_reply(): void
    {
        Http::fake(['*/tokenize' => Http::response(['unexpected' => true])]);

        $this->expectException(ModelRequestFailed::class);

        $this->counter()->count('Hello');
    }

    public function test_a_rejected_key_is_an_error_not_a_zero(): void
    {
        Http::fake(['*/tokenize' => Http::response(['error' => ['code' => 401, 'message' => 'Invalid API Key', 'type' => 'authentication_error']], 401)]);

        $this->expectException(InvalidRequest::class);

        $this->counter()->count('Hello');
    }

    public function test_a_server_that_is_down_is_a_retryable_error_not_a_zero(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        try {
            $this->counter()->count('Hello');

            $this->fail('Expected ProviderUnavailable');
        } catch (ProviderUnavailable $exception) {
            $this->assertTrue($exception->isRetryable());
            $this->assertStringContainsString('http://llama.test:8080', $exception->getMessage());
        }
    }

    private function counter(): LlamaServerTokenCounter
    {
        return LlamaServerTokenCounter::forProvider([
            'base_url' => 'http://llama.test:8080/v1',
            'api_key' => 'secret-key',
        ]);
    }
}
