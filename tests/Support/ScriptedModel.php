<?php

namespace Tests\Support;

use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\FinishReason;
use App\Ai\Contracts\Stream\Completed;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Contracts\Usage;
use Generator;
use Throwable;

final class ScriptedModel implements ChatModel
{
    public int $calls = 0;

    public array $received = [];

    private array $outcomes;

    public function __construct(private readonly string $name, array $outcomes = [])
    {
        $this->outcomes = $outcomes;
    }

    public static function answering(string $name, string $content): self
    {
        return new self($name, [$content]);
    }

    public function then(Throwable|string $outcome): self
    {
        $this->outcomes[] = $outcome;

        return $this;
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        $this->received[] = $request;

        $this->calls++;

        return $this->respond($this->next());
    }

    public function stream(ChatRequest $request): Generator
    {
        $this->received[] = $request;

        $this->calls++;

        $outcome = $this->next();

        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        yield new Delta($outcome);

        $response = $this->respond($outcome);

        yield new Completed($response);

        return $response;
    }

    public function supports(Capability $capability): bool
    {
        return false;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function raw(string $method, string $path, array $body = []): array
    {
        return [];
    }

    private function next(): Throwable|string
    {
        if (count($this->outcomes) > 1) {
            return array_shift($this->outcomes);
        }

        return $this->outcomes[0];
    }

    private function respond(Throwable|string $outcome): ChatResponse
    {
        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        return new ChatResponse($this->name.'-id', $this->name.'-model', $outcome, [], FinishReason::Stop, new Usage(10, 5), []);
    }
}
