<?php

namespace App\Ai\Tools;

use App\Ai\Events\ToolCalled;
use App\Ai\Http\AiHttpClient;
use App\Ai\Http\ChatResponse;
use App\Models\PendingToolCall;
use App\Models\ToolCall;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ToolRunner
{
    public const MAX_ROUNDS = 5;

    public function __construct(
        private readonly AiHttpClient $client,
        private readonly ToolRegistry $tools,
        private readonly ToolGate $gate,
    ) {}

    public function run(array $messages, ToolContext $ctx, array $options = [], string $feature = 'tools', ?string $promptVersion = null): ToolRunResult
    {
        return $this->loop($messages, $ctx, $options, $feature, $promptVersion, 1);
    }

    public function resume(string $runId): ToolRunResult
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

    private function loop(array $messages, ToolContext $ctx, array $options, string $feature, ?string $promptVersion, int $firstRound): ToolRunResult
    {
        $requestOptions = $this->withToolOptions($options);

        $inputCount = count($messages);

        $modelCallIds = [];

        $response = null;

        for ($round = $firstRound; $round <= self::MAX_ROUNDS; $round++) {
            $response = $this->client->chat($messages, $requestOptions, $feature, $promptVersion, conversationId: $ctx->conversationId);

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

    private function executeCall(array $call, ToolContext $ctx): ToolResult
    {
        $name = (string) ($call['function']['name'] ?? '');

        if (! $this->tools->has($name)) {
            $result = ToolResult::error('unknown tool');

            return $this->record($name, [], $result, $ctx, ToolCall::DECISION_DENIED);
        }

        $args = $this->decodeArguments($call['function']['arguments'] ?? '');

        if ($args === false) {
            $result = ToolResult::error('invalid arguments', ['arguments' => 'must be a JSON object']);

            return $this->record($name, [], $result, $ctx, ToolCall::DECISION_INVALID);
        }

        return $this->executeOne($this->tools->get($name), $args, $ctx);
    }

    private function decodeArguments(mixed $raw): array|false
    {
        if ($raw === '') {
            return [];
        }

        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        if (! is_array($decoded)) {
            return false;
        }

        return $decoded;
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
