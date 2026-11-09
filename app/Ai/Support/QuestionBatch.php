<?php

namespace App\Ai\Support;

use App\Ai\Exceptions\UnreadableQuestionBatch;

final readonly class QuestionBatch
{
    public function __construct(public array $questions) {}

    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            throw UnreadableQuestionBatch::fileMissing($path);
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        $questions = is_array($decoded)
            ? ($decoded['questions'] ?? null)
            : null;

        if (! is_array($questions)) {
            throw UnreadableQuestionBatch::notAQuestionList($path);
        }

        if ($questions === []) {
            throw UnreadableQuestionBatch::notAQuestionList($path);
        }

        foreach ($questions as $question) {
            if (! is_string($question)) {
                throw UnreadableQuestionBatch::notAQuestionList($path);
            }
        }

        return new self(array_values($questions));
    }
}
