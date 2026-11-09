<?php

namespace App\Ai\Drivers;

use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\FinishReason;
use App\Ai\Contracts\Stream\Completed;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Contracts\Stream\ToolCallArguments;
use App\Ai\Contracts\Stream\ToolCallStarted;
use App\Ai\Contracts\Usage;
use App\Ai\Exceptions\InvalidFakeScript;
use App\Ai\Exceptions\UnsupportedRawRequest;
use App\Ai\Testing\FakeLedger;
use App\Ai\Testing\JsonSchemaCheck;
use App\Ai\Testing\ScriptedReply;
use Generator;

final class FakeDriver implements ChatModel
{
    private static int $responses = 0;

    public function __construct(
        private readonly array $config,
        private readonly FakeLedger $ledger = new FakeLedger,
    ) {}

    public function ledger(): FakeLedger
    {
        return $this->ledger;
    }

    public function name(): string
    {
        return (string) $this->config['name'];
    }

    public function supports(Capability $capability): bool
    {
        return true;
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        return $this->respond($request, $this->scriptedFor($request));
    }

    public function stream(ChatRequest $request): Generator
    {
        $reply = $this->scriptedFor($request);

        $response = $this->respond($request, $reply);

        foreach ($reply->deltas as $delta) {
            yield new Delta($delta);
        }

        if ($reply->deltas === [] && $response->content !== null && $response->content !== '') {
            yield new Delta($response->content);
        }

        foreach ($response->toolCalls as $index => $call) {
            yield new ToolCallStarted($index, $call['function']['name']);

            yield new ToolCallArguments($index, $call['function']['arguments']);
        }

        yield new Completed($response);

        return $response;
    }

    public function raw(string $method, string $path, array $body = []): array
    {
        throw UnsupportedRawRequest::for($this->name());
    }

    private function scriptedFor(ChatRequest $request): ScriptedReply
    {
        $this->ledger->record($this->name(), $request);

        $reply = $this->ledger->pull($this->name(), $request);

        if ($reply->failure !== null) {
            throw $reply->failure;
        }

        return $reply;
    }

    private function respond(ChatRequest $request, ScriptedReply $reply): ChatResponse
    {
        $content = $reply->text();

        $this->assertMatchesSchema($request, $content);

        self::$responses++;

        $hasToolCalls = $reply->toolCalls !== [];

        return new ChatResponse(
            id: 'fake-'.self::$responses,
            model: (string) ($request->model ?? $this->config['model'] ?? 'fake'),
            content: $hasToolCalls && $content === null ? null : (string) $content,
            toolCalls: $reply->toolCalls,
            finishReason: $reply->finishReason ?? ($hasToolCalls ? FinishReason::ToolCalls : FinishReason::Stop),
            usage: new Usage(
                promptTokens: (int) ceil(strlen((string) json_encode($request->messages)) / 4),
                completionTokens: (int) ceil(strlen((string) $content) / 4),
                estimated: true,
            ),
            raw: [],
        );
    }

    private function assertMatchesSchema(ChatRequest $request, ?string $content): void
    {
        $schema = $request->responseFormat['json_schema']['schema'] ?? null;

        if ($schema === null) {
            return;
        }

        if ($content === null) {
            return;
        }

        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw InvalidFakeScript::failsSchema($request->feature, 'the content is not valid JSON');
        }

        $violations = JsonSchemaCheck::violations($decoded, $schema);

        if ($violations !== []) {
            throw InvalidFakeScript::failsSchema($request->feature, implode('; ', $violations));
        }
    }
}
