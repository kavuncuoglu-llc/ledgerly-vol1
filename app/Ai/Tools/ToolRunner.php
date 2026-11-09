<?php

namespace App\Ai\Tools;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Contracts\Stream\StreamEvent;
use App\Ai\Contracts\Stream\ToolCallStarted;
use App\Ai\Events\ToolCalled;
use App\Ai\Http\CallDetails;
use App\Ai\Streaming\StreamTaps;
use App\Ai\Tokens\TokenCounter;
use App\Models\PendingToolCall;
use App\Models\ToolCall;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ToolRunner
{
    public const MAX_ROUNDS = 5;

    public function __construct(
        private readonly AiManager $ai,
        private readonly ToolRegistry $tools,
        private readonly ToolGate $gate,
        private readonly TokenCounter $counter,
        private readonly int $escalateAfterRounds = 0,
    ) {}

    public function run(array $messages, ToolContext $ctx, array $options = [], string $feature = 'tools', ?string $promptVersion = null, ?CallDetails $details = null, ?StreamTaps $taps = null): ToolRunResult
    {
        return $this->loop($messages, $ctx, $options, $feature, $promptVersion, 1, $details, $taps);
    }

    public function resume(string $runId, ?StreamTaps $taps = null): ToolRunResult
    {
        $rows = PendingToolCall::query()
            ->where('run_id', $runId)
            ->orderBy('position')
            ->get();

        $first = $rows->first();

        $messages = $first->messages;

        foreach ($rows as $row) {
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $row->tool_call_id,
                'content' => json_encode($row->result),
            ];
        }

        return $this->loop(
            messages: $messages,
            ctx: $this->contextFor($first),
            options: $first->options ?? [],
            feature: $first->feature ?? 'tools',
            promptVersion: $first->prompt_version,
            firstRound: min($first->round + 1, self::MAX_ROUNDS),
            taps: $taps,
        );
    }

    public function restrictedTo(array $toolNames): self
    {
        return new self(
            ai: $this->ai,
            tools: $this->tools->only($toolNames),
            gate: $this->gate,
            counter: $this->counter,
            escalateAfterRounds: $this->escalateAfterRounds,
        );
    }

    public function escalatingAfter(int $rounds): self
    {
        return new self(
            ai: $this->ai,
            tools: $this->tools,
            gate: $this->gate,
            counter: $this->counter,
            escalateAfterRounds: $rounds,
        );
    }

    public function executeOne(Tool $tool, array $args, ToolContext $ctx): ToolResult
    {
        try {
            $validated = $tool->validate($args);
        } catch (ValidationException $exception) {
            $result = ToolResult::error('invalid arguments', $exception->errors());

            return $this->record($tool->name(), $args, $result, $ctx, ToolCall::DECISION_INVALID);
        }

        $decision = $this->gate->check($tool, $validated, $ctx);

        if (! $decision->allowed) {
            $result = ToolResult::denied($decision->reason);

            return $this->record($tool->name(), $args, $result, $ctx, ToolCall::DECISION_DENIED);
        }

        if ($decision->needsApproval) {
            return $this->holdForApproval($tool, $validated, $decision, $ctx);
        }

        return $this->perform($tool, $validated, $ctx, ToolCall::DECISION_ALLOWED);
    }

    public function executeApproved(PendingToolCall $pending): ToolResult
    {
        $ctx = $this->contextFor($pending);

        if (! $this->tools->has($pending->tool)) {
            $result = ToolResult::error('unknown tool');

            return $this->record($pending->tool, $pending->arguments, $result, $ctx, ToolCall::DECISION_DENIED);
        }

        $tool = $this->tools->get($pending->tool);

        $decision = $this->gate->check($tool, $pending->arguments, $ctx, approved: true);

        if (! $decision->allowed) {
            $result = ToolResult::denied($decision->reason);

            return $this->record($tool->name(), $pending->arguments, $result, $ctx, ToolCall::DECISION_DENIED);
        }

        return $this->perform($tool, $pending->arguments, $ctx, ToolCall::DECISION_APPROVED);
    }

    public function recordDecline(PendingToolCall $pending): ToolResult
    {
        return $this->record(
            toolName: $pending->tool,
            args: $pending->arguments,
            result: ToolResult::declined(),
            ctx: $this->contextFor($pending),
            decision: ToolCall::DECISION_DECLINED,
        );
    }

    private function loop(array $messages, ToolContext $ctx, array $options, string $feature, ?string $promptVersion, int $firstRound, ?CallDetails $details = null, ?StreamTaps $taps = null): ToolRunResult
    {
        $requestOptions = $this->withToolOptions($options);

        $inputCount = count($messages);

        $modelCallIds = [];

        $response = null;

        for ($round = $firstRound; $round <= self::MAX_ROUNDS; $round++) {
            $response = $this->complete(
                $this->modelFor($feature, $round),
                $messages,
                $requestOptions,
                $feature,
                $promptVersion,
                $ctx,
                $this->detailsForRound($details, $requestOptions, $messages, $inputCount),
                $taps,
            );

            $modelCallIds[] = $response->modelCallId;

            if ($response->toolCalls === []) {
                return new ToolRunResult($response, $messages, rounds: $round, inputCount: $inputCount, modelCallIds: $modelCallIds);
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => $response->content,
                'tool_calls' => $response->toolCalls,
            ];

            $outcome = $this->executeCalls($response, $ctx, $messages);

            $messages = $outcome['messages'];

            if ($outcome['pending'] !== []) {
                $this->bindPendingToRun($outcome['pending'], $messages, $options, $feature, $promptVersion, $round);

                return new ToolRunResult($response, $messages, rounds: $round, pending: $outcome['pending'], inputCount: $inputCount, modelCallIds: $modelCallIds);
            }
        }

        return new ToolRunResult($response, $messages, rounds: self::MAX_ROUNDS, hitCap: true, inputCount: $inputCount, modelCallIds: $modelCallIds);
    }

    private function modelFor(string $feature, int $round): ChatModel
    {
        if ($this->escalateAfterRounds === 0) {
            return $this->ai->for($feature);
        }

        if ($round <= $this->escalateAfterRounds) {
            return $this->ai->for($feature);
        }

        if (! $this->ai->hasEscalation($feature)) {
            return $this->ai->for($feature);
        }

        return $this->ai->escalation($feature);
    }

    private function complete(ChatModel $model, array $messages, array $requestOptions, string $feature, ?string $promptVersion, ToolContext $ctx, ?CallDetails $details, ?StreamTaps $taps): ChatResponse
    {
        $request = ChatRequest::fromOptions(
            $messages,
            $requestOptions,
            $feature,
            $promptVersion,
            conversationId: $ctx->conversationId,
            details: $details,
        );

        if ($taps === null) {
            return $model->chat($request);
        }

        $stream = $model->stream($request);

        foreach ($stream as $item) {
            $this->relay($item, $taps);
        }

        return $stream->getReturn();
    }

    private function relay(StreamEvent $item, StreamTaps $taps): void
    {
        if ($item instanceof ToolCallStarted) {
            ($taps->onToolStart)($item->name);

            return;
        }

        if ($item instanceof Delta) {
            ($taps->onDelta)($item->text);
        }
    }

    private function detailsForRound(?CallDetails $details, array $requestOptions, array $messages, int $inputCount): ?CallDetails
    {
        if ($details === null) {
            return null;
        }

        if (count($messages) === $inputCount) {
            return $this->withToolSlot($details, $requestOptions);
        }

        $produced = json_encode(array_slice($messages, $inputCount));

        return $this->withToolSlot($details, $requestOptions)->forFollowUpRound([
            'tool_results' => $this->counter->count((string) $produced),
        ]);
    }

    private function withToolSlot(CallDetails $details, array $requestOptions): CallDetails
    {
        if (empty($requestOptions['tools'])) {
            return $details;
        }

        return new CallDetails(
            contextBreakdown: [
                ...($details->contextBreakdown ?? []),
                'tools' => $this->counter->count((string) json_encode($requestOptions['tools'])),
            ],
            semanticCacheHit: $details->semanticCacheHit,
        );
    }

    private function withToolOptions(array $options): array
    {
        $definitions = $this->tools->definitions();

        if ($definitions === []) {
            return $options;
        }

        $options['tools'] = $definitions;

        if ($this->tools->hasHighRiskTools()) {
            $options['parallel_tool_calls'] ??= false;
        }

        return $options;
    }

    private function executeCalls(ChatResponse $response, ToolContext $ctx, array $messages): array
    {
        $pendingResults = [];

        foreach ($response->toolCalls as $call) {
            $callContext = $ctx->forToolCall((string) ($call['id'] ?? ''), $response->modelCallId);

            $result = $this->executeCall($call, $callContext);

            if ($result->isPending()) {
                $pendingResults[] = $result;

                continue;
            }

            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $call['id'] ?? '',
                'content' => $result->toJson(),
            ];
        }

        return [
            'pending' => $pendingResults,
            'messages' => $messages,
        ];
    }

    public function executeCall(array $call, ToolContext $ctx, ?ToolRegistry $registry = null): ToolResult
    {
        $registry ??= $this->tools;

        $name = (string) ($call['function']['name'] ?? '');

        if (! $registry->has($name)) {
            $result = ToolResult::error('unknown tool');

            return $this->record($name, [], $result, $ctx, ToolCall::DECISION_DENIED);
        }

        $args = $this->decodeArguments($call['function']['arguments'] ?? '');

        if ($args === false) {
            $result = ToolResult::error('invalid arguments', ['arguments' => 'must be a JSON object']);

            return $this->record($name, [], $result, $ctx, ToolCall::DECISION_INVALID);
        }

        return $this->executeOne($registry->get($name), $args, $ctx);
    }

    private function decodeArguments(mixed $raw): array|false
    {
        if ($raw === '') {
            return [];
        }

        if (is_array($raw)) {
            return $this->withoutNullStrings($raw);
        }

        $decoded = json_decode((string) $raw, true);

        if (! is_array($decoded)) {
            return false;
        }

        return $this->withoutNullStrings($decoded);
    }

    private function withoutNullStrings(array $arguments): array
    {
        return array_map(
            fn (mixed $value): mixed => $value === 'null'
                ? null
                : $value,
            $arguments,
        );
    }

    private function perform(Tool $tool, array $validated, ToolContext $ctx, string $decision): ToolResult
    {
        $started = hrtime(true);

        try {
            $result = $tool->handle($tool->hydrate($validated), $ctx);
        } catch (Throwable $exception) {
            report($exception);

            $result = ToolResult::error('tool failed', [
                'message' => 'The lookup could not be completed.',
            ]);
        }

        $ctx->rememberCall($tool->name());

        $elapsedMs = (int) ((hrtime(true) - $started) / 1_000_000);

        return $this->record($tool->name(), $validated, $result, $ctx, $decision, $elapsedMs);
    }

    private function holdForApproval(Tool $tool, array $validated, GateDecision $decision, ToolContext $ctx): ToolResult
    {
        $pending = PendingToolCall::create([
            'user_id' => $ctx->user->id,
            'conversation_id' => $ctx->conversationId,
            'tool' => $tool->name(),
            'tool_call_id' => $ctx->toolCallId ?? '',
            'arguments' => $validated,
            'summary' => $decision->summary,
            'status' => PendingToolCall::STATUS_PENDING,
            'source' => $ctx->source,
            'token_name' => $ctx->tokenName,
        ]);

        $result = ToolResult::pending(
            pendingId: $pending->id,
            summary: $decision->summary,
            approvalUrl: route('tool-approvals.show', $pending),
        );

        return $this->record($tool->name(), $validated, $result, $ctx, ToolCall::DECISION_PENDING_APPROVAL);
    }

    private function bindPendingToRun(array $pendingResults, array $messages, array $options, string $feature, ?string $promptVersion, int $round): void
    {
        $runId = (string) str()->uuid();

        foreach ($pendingResults as $position => $result) {
            PendingToolCall::query()
                ->whereKey($result->pendingId)
                ->update([
                    'run_id' => $runId,
                    'round' => $round,
                    'position' => $position,
                    'messages' => json_encode($messages),
                    'options' => json_encode($options),
                    'feature' => $feature,
                    'prompt_version' => $promptVersion,
                ]);
        }
    }

    private function contextFor(PendingToolCall $pending): ToolContext
    {
        return new ToolContext(
            user: $pending->user,
            conversationId: $pending->conversation_id,
            source: $pending->source,
            tokenName: $pending->token_name,
            toolCallId: $pending->tool_call_id,
        );
    }

    private function record(string $toolName, array $args, ToolResult $result, ToolContext $ctx, string $decision, int $durationMs = 0): ToolResult
    {
        ToolCalled::dispatch($toolName, $args, $result, $ctx, $decision, $durationMs);

        return $result;
    }
}
