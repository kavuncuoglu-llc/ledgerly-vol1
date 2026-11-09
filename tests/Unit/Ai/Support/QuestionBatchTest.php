<?php

namespace Tests\Unit\Ai\Support;

use App\Ai\Exceptions\UnreadableQuestionBatch;
use App\Ai\Support\QuestionBatch;
use PHPUnit\Framework\TestCase;

class QuestionBatchTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'batch');
    }

    protected function tearDown(): void
    {
        unlink($this->path);

        parent::tearDown();
    }

    public function test_from_file_reads_the_questions_list(): void
    {
        file_put_contents($this->path, json_encode(['questions' => ['One?', 'Two?'], 'description' => 'ignored']));

        $batch = QuestionBatch::fromFile($this->path);

        $this->assertSame(['One?', 'Two?'], $batch->questions);
    }

    public function test_from_file_reads_the_shipped_howto_sample(): void
    {
        $batch = QuestionBatch::fromFile(__DIR__.'/../../../../storage/samples/howto.json');

        $this->assertCount(20, $batch->questions);
    }

    public function test_from_file_rejects_a_missing_file(): void
    {
        $this->expectException(UnreadableQuestionBatch::class);

        QuestionBatch::fromFile('/nonexistent/howto.json');
    }

    public function test_from_file_rejects_json_that_is_not_a_list_of_strings(): void
    {
        foreach (['not json', '{"questions": []}', '{"questions": [1]}', '[]', '{"other": ["a"]}'] as $contents) {
            file_put_contents($this->path, $contents);

            try {
                QuestionBatch::fromFile($this->path);
                $this->fail("Accepted {$contents}");
            } catch (UnreadableQuestionBatch $exception) {
                $this->assertStringContainsString('questions', $exception->getMessage());
            }
        }
    }
}
