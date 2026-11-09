<?php

namespace Tests\Support;

trait FakesStreamedModel
{
    protected function streamedAnswer(string $content, ?array $pieces = null, int $promptTokens = 80, int $completionTokens = 20): string
    {
        $pieces ??= preg_split('/(?<=\s)/', $content, -1, PREG_SPLIT_NO_EMPTY);

        $chunks = array_map(fn (string $piece): array => $this->contentChunk($piece), $pieces);

        $chunks[] = $this->finishChunk('stop', $promptTokens, $completionTokens);

        return $this->serverSentEvents($chunks);
    }

    protected function streamedToolCall(string $id, string $name, array $arguments): string
    {
        return $this->serverSentEvents([
            $this->toolChunk($id, $name, json_encode($arguments)),
            $this->finishChunk('tool_calls', 40, 9),
        ]);
    }

    private function contentChunk(string $piece): array
    {
        return [
            'id' => 'chatcmpl-stream',
            'model' => 'llama3.1:8b',
            'choices' => [
                [
                    'index' => 0,
                    'delta' => ['content' => $piece],
                    'finish_reason' => null,
                ],
            ],
        ];
    }

    private function toolChunk(string $id, string $name, string $arguments): array
    {
        return [
            'id' => 'chatcmpl-stream',
            'model' => 'llama3.1:8b',
            'choices' => [
                [
                    'index' => 0,
                    'delta' => [
                        'tool_calls' => [
                            [
                                'index' => 0,
                                'id' => $id,
                                'function' => [
                                    'name' => $name,
                                    'arguments' => $arguments,
                                ],
                            ],
                        ],
                    ],
                    'finish_reason' => null,
                ],
            ],
        ];
    }

    private function finishChunk(string $reason, int $promptTokens, int $completionTokens): array
    {
        return [
            'id' => 'chatcmpl-stream',
            'model' => 'llama3.1:8b',
            'choices' => [
                [
                    'index' => 0,
                    'delta' => [],
                    'finish_reason' => $reason,
                ],
            ],
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
            ],
        ];
    }

    private function serverSentEvents(array $chunks): string
    {
        $lines = array_map(fn (array $chunk): string => 'data: '.json_encode($chunk)."\n\n", $chunks);

        return implode('', $lines)."data: [DONE]\n\n";
    }
}
