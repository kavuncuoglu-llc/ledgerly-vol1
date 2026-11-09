<?php

namespace Tests\Unit\Ai\Retrieval;

use App\Ai\Exceptions\UnreadableGoldenSet;
use App\Ai\Retrieval\GoldenSet;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class GoldenSetTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/golden-'.uniqid().'.jsonl';
    }

    protected function tearDown(): void
    {
        File::delete($this->path);

        parent::tearDown();
    }

    public function test_from_file_reads_one_question_per_line(): void
    {
        File::put($this->path, "{\"id\": 1, \"question\": \"Late fee?\", \"chunks\": [\"late-fees›Overview\"]}\n\n{\"id\": 2, \"question\": \"Pizza?\", \"chunks\": []}\n");

        $questions = GoldenSet::fromFile($this->path);

        $this->assertCount(2, $questions);
        $this->assertSame('Late fee?', $questions[0]->question);
        $this->assertTrue($questions[1]->expectsNothing());
    }

    public function test_from_file_rejects_a_missing_file(): void
    {
        $this->expectException(UnreadableGoldenSet::class);

        GoldenSet::fromFile($this->path);
    }

    public function test_from_file_rejects_a_line_that_is_not_json(): void
    {
        File::put($this->path, "not json\n");

        $this->expectExceptionMessage('Line 1');

        GoldenSet::fromFile($this->path);
    }

    public function test_from_file_rejects_an_empty_file(): void
    {
        File::put($this->path, "\n");

        $this->expectException(UnreadableGoldenSet::class);

        GoldenSet::fromFile($this->path);
    }

    public function test_the_committed_golden_set_has_120_questions_of_which_12_expect_nothing(): void
    {
        $questions = GoldenSet::fromFile(base_path('storage/golden/retrieval.jsonl'));

        $this->assertCount(120, $questions);
        $this->assertCount(12, array_filter($questions, fn ($question): bool => $question->expectsNothing()));
    }
}
