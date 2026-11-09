<?php

namespace App\Ai\Doctor\Vllm\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Vllm\VllmCheck;
use App\Ai\Doctor\Vllm\VllmDoctorContext;
use App\Ai\Prompts\TicketClassifyPrompt;

final class StructuredOutputCheck implements VllmCheck
{
    public function run(VllmDoctorContext $context): array
    {
        $reply = $context->client->chatCompletion([
            'model' => $context->model(),
            'messages' => TicketClassifyPrompt::messages('Why was I charged a late fee on invoice 4471?'),
            'max_tokens' => TicketClassifyPrompt::GRAMMAR_MAX_TOKENS * 4,
            'temperature' => 0,
            'stream' => false,
            'response_format' => $this->responseFormat(),
        ], $context->probeTimeoutSeconds);

        if (! $reply->ok()) {
            return [CheckResult::fail("structured output request failed: {$reply->errorMessage()}. Features that send a response_format (ticket classification, extraction) would fail the same way.")];
        }

        $content = (string) ($reply->body['choices'][0]['message']['content'] ?? '');

        $category = $this->categoryOf($content);

        if (! in_array($category, TicketClassifyPrompt::LABELS, true)) {
            return [CheckResult::fail("response_format answered [{$content}], which is not a JSON object with one of the five labels: the schema was not applied.")];
        }

        return [CheckResult::pass("schema-constrained completion answered [{$category}] in {$this->seconds($reply->milliseconds)}")];
    }

    private function categoryOf(string $content): string
    {
        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            return '';
        }

        return (string) ($decoded['category'] ?? '');
    }

    private function responseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'ticket_category',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'category' => [
                            'type' => 'string',
                            'enum' => TicketClassifyPrompt::LABELS,
                        ],
                    ],
                    'required' => ['category'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    private function seconds(int $milliseconds): string
    {
        return number_format($milliseconds / 1000, 1).' s';
    }
}
