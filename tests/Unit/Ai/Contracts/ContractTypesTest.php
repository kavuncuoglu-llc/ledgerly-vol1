<?php

namespace Tests\Unit\Ai\Contracts;

use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\FinishReason;
use App\Ai\Contracts\Usage;
use App\Ai\Exceptions\ContextTooLong;
use App\Ai\Exceptions\InvalidRequest;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Exceptions\RateLimited;
use App\Ai\Testing\JsonSchemaCheck;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ContractTypesTest extends TestCase
{
    public function test_a_known_finish_reason_maps_through_the_vocabulary(): void
    {
        $reason = FinishReason::normalize('end_turn', ['end_turn' => FinishReason::Stop]);

        $this->assertSame(FinishReason::Stop, $reason);
    }

    public function test_a_missing_finish_reason_is_a_stop(): void
    {
        $this->assertSame(FinishReason::Stop, FinishReason::normalize(null, []));
    }

    public function test_an_unrecognized_finish_reason_becomes_truncated_and_is_logged_with_the_raw_value(): void
    {
        Log::spy();

        $reason = FinishReason::normalize('brand_new', ['stop' => FinishReason::Stop], 'frontier');

        $this->assertSame(FinishReason::Truncated, $reason);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context === ['provider' => 'frontier', 'finish_reason' => 'brand_new'])->once();
    }

    public function test_usage_to_array_lists_only_what_is_known(): void
    {
        $this->assertSame(['prompt_tokens' => 3, 'completion_tokens' => 4], (new Usage(3, 4))->toArray());
        $this->assertSame(
            ['prompt_tokens' => 3, 'completion_tokens' => 4, 'cached_tokens' => 2, 'reasoning_tokens' => 1, 'estimated' => true],
            (new Usage(3, 4, 2, 1, true))->toArray(),
        );
    }

    public function test_from_options_splits_the_legacy_array_into_typed_fields(): void
    {
        $request = ChatRequest::fromOptions(
            messages: [['role' => 'user', 'content' => 'Hi']],
            options: [
                'profile' => 'deterministic',
                'model' => 'm',
                'tools' => [['name' => 't']],
                'tool_choice' => 'auto',
                'response_format' => ['type' => 'json_object'],
                'cache_breakpoints_after' => [0, 2],
                'max_tokens' => 10,
            ],
            feature: 'ticket.extract',
            promptVersion: 'v1',
            repaired: true,
            conversationId: 9,
        );

        $this->assertSame('deterministic', $request->profile);
        $this->assertSame('m', $request->model);
        $this->assertSame([['name' => 't']], $request->tools);
        $this->assertSame('auto', $request->toolChoice);
        $this->assertSame(['type' => 'json_object'], $request->responseFormat);
        $this->assertSame([0, 2], $request->cacheBreakpointsAfter);
        $this->assertSame(['max_tokens' => 10], $request->options);
        $this->assertSame('v1', $request->promptVersion);
        $this->assertTrue($request->repaired);
        $this->assertSame(9, $request->conversationId);
    }

    public function test_a_request_without_a_profile_key_leaves_the_profile_unspecified(): void
    {
        $request = ChatRequest::fromOptions([], [], 'x');

        $this->assertNull($request->profile);
    }

    public function test_rejected_maps_statuses_onto_the_exception_hierarchy(): void
    {
        $this->assertInstanceOf(RateLimited::class, ModelRequestFailed::rejected(429, 'slow down', 7));
        $this->assertSame(7, ModelRequestFailed::rejected(429, 'slow down', 7)->retryAfterSeconds);
        $this->assertInstanceOf(ProviderUnavailable::class, ModelRequestFailed::rejected(503, 'busy'));
        $this->assertInstanceOf(ProviderUnavailable::class, ModelRequestFailed::rejected(529, 'overloaded'));
        $this->assertInstanceOf(InvalidRequest::class, ModelRequestFailed::rejected(401, 'bad key'));
        $this->assertInstanceOf(ContextTooLong::class, ModelRequestFailed::rejected(400, "This model's maximum context length is 8192 tokens."));
    }

    public function test_only_rate_limits_and_unavailability_are_retryable(): void
    {
        $this->assertTrue((new RateLimited)->isRetryable());
        $this->assertTrue((new ProviderUnavailable)->isRetryable());
        $this->assertFalse((new ContextTooLong)->isRetryable());
        $this->assertFalse((new InvalidRequest)->isRetryable());
    }

    public function test_the_schema_check_reports_type_enum_required_and_extra_property_violations(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'category' => ['type' => 'string', 'enum' => ['billing']],
                'amount' => ['type' => ['number', 'null']],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['category'],
            'additionalProperties' => false,
        ];

        $this->assertSame([], JsonSchemaCheck::violations(['category' => 'billing', 'amount' => null, 'tags' => ['a']], $schema));
        $this->assertNotSame([], JsonSchemaCheck::violations(['amount' => 1], $schema));
        $this->assertNotSame([], JsonSchemaCheck::violations(['category' => 'other'], $schema));
        $this->assertNotSame([], JsonSchemaCheck::violations(['category' => 'billing', 'extra' => 1], $schema));
        $this->assertNotSame([], JsonSchemaCheck::violations(['category' => 'billing', 'tags' => [1]], $schema));
        $this->assertNotSame([], JsonSchemaCheck::violations('billing', $schema));
    }
}
