<?php

namespace Tests\Support;

use App\Ai\Cache\HelpVersion;
use App\Models\CachedAnswer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

trait FakesSemanticCache
{
    private array $semanticFake = [];

    protected function disableIndexScans(): void
    {
        DB::statement('SET LOCAL enable_indexscan = off');
    }

    protected function unitVector(int $axis): array
    {
        $vector = array_fill(0, 768, 0.0);
        $vector[$axis] = 1.0;

        return $vector;
    }

    protected function vectorWithCosine(int $axis, int $otherAxis, float $cosine): array
    {
        $vector = array_fill(0, 768, 0.0);
        $vector[$axis] = $cosine;
        $vector[$otherAxis] = sqrt(1 - $cosine ** 2);

        return $vector;
    }

    protected function fakeSemanticStack(array $vectorsByQuestion, string $classification = 'howto', string $answer = 'Open Recurring, choose New recurring invoice, pick an interval and save the schedule.'): void
    {
        $this->semanticFake = compact('vectorsByQuestion', 'classification', 'answer');

        Http::fake(function (Request $request) {
            ['vectorsByQuestion' => $vectorsByQuestion, 'classification' => $classification, 'answer' => $answer] = $this->semanticFake;

            if (str_ends_with($request->url(), '/embeddings')) {
                $question = substr($request['input'], strlen('search_query: '));

                return Http::response([
                    'data' => [
                        [
                            'embedding' => $vectorsByQuestion[$question] ?? $this->unitVector(767),
                        ],
                    ],
                ]);
            }

            if (isset($request['response_format'])) {
                return Http::response($this->chatBody($this->classificationJson($classification)));
            }

            return Http::response($this->chatBody($answer));
        });
    }

    protected function cachedAnswer(array $attributes = []): CachedAnswer
    {
        return CachedAnswer::create([
            'tenant_id' => null,
            'category' => 'howto',
            'question' => 'How do I set up recurring invoices?',
            'answer' => 'Open Recurring and choose New recurring invoice.',
            'help_version' => app(HelpVersion::class)->current(),
            'status' => CachedAnswer::STATUS_AUTO,
            'embedding' => $this->unitVector(0),
            ...$attributes,
        ]);
    }

    protected function chatBody(string $content, int $promptTokens = 120): array
    {
        return [
            'id' => 'chatcmpl-1',
            'model' => 'llama3.1:8b',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => $content,
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => 20,
            ],
        ];
    }

    private function classificationJson(string $category): string
    {
        return json_encode([
            'category' => $category,
            'invoice_number' => null,
            'customer_email' => null,
            'amount_mentioned' => null,
            'date_mentioned' => null,
        ]);
    }
}
