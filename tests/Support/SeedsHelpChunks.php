<?php

namespace Tests\Support;

use App\Models\HelpChunk;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

trait SeedsHelpChunks
{
    protected function axisVector(int $axis): array
    {
        $vector = array_fill(0, 768, 0.0);
        $vector[$axis] = 1.0;

        return $vector;
    }

    protected function blendedVector(int $axis, int $otherAxis, float $cosine): array
    {
        $vector = array_fill(0, 768, 0.0);
        $vector[$axis] = $cosine;
        $vector[$otherAxis] = sqrt(1 - $cosine ** 2);

        return $vector;
    }

    protected function seedChunk(string $slug, string $section, string $body, array $vector, array $tags = [], string $category = 'billing', string $version = 'v1', int $position = 1): HelpChunk
    {
        return HelpChunk::query()->create([
            'source' => "help/{$category}/{$slug}.md",
            'position' => $position,
            'heading' => "{$slug} › {$section}",
            'content' => "{$slug} › {$section}\n\n{$body}",
            'tokens' => 20,
            'embedding' => $vector,
            'title' => $slug,
            'heading_path' => ucfirst($category)." › {$slug} › {$section}",
            'tags' => $tags === []
                ? ['area' => $category]
                : $tags,
            'help_version' => $version,
            'source_modified_at' => now(),
        ]);
    }

    protected function fakeEmbeddingsAndChat(array $vectorsByQuestion, array $replies = []): void
    {
        $remaining = $replies;

        Http::fake(function (Request $request) use ($vectorsByQuestion, &$remaining) {
            if (str_ends_with($request->url(), '/embeddings')) {
                $question = substr($request['input'], strlen('search_query: '));

                return Http::response([
                    'data' => [
                        [
                            'embedding' => $vectorsByQuestion[$question] ?? $this->axisVector(767),
                        ],
                    ],
                ]);
            }

            return Http::response(array_shift($remaining));
        });
    }

    protected function chatReply(string $content): array
    {
        return [
            'id' => 'chatcmpl-test',
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
                'prompt_tokens' => 100,
                'completion_tokens' => 20,
            ],
        ];
    }

    protected function disableVectorIndexScans(): void
    {
        DB::statement('SET LOCAL enable_indexscan = off');
    }
}
