<?php

namespace Tests\Unit\Ai\Load;

use App\Ai\Exceptions\UnusableLoadTest;
use App\Ai\Load\Percentile;
use App\Ai\Load\ShapeSummary;
use App\Ai\Load\TrafficShape;
use App\Ai\Load\TrafficShapeFile;
use PHPUnit\Framework\TestCase;

class TrafficShapeTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/ledgerly-shape-'.uniqid().'.jsonl';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    public function test_a_shape_file_round_trips_one_json_object_per_line(): void
    {
        TrafficShapeFile::write($this->path, [
            new TrafficShape('support.reply', 2100, 180, 450),
            new TrafficShape('support.reply', 900, 60, 450),
        ]);

        $shapes = TrafficShapeFile::read($this->path);

        $this->assertCount(2, $shapes);
        $this->assertEquals(new TrafficShape('support.reply', 2100, 180, 450), $shapes[0]);
        $this->assertCount(2, explode("\n", trim((string) file_get_contents($this->path))));
    }

    public function test_writing_creates_the_directory(): void
    {
        $nested = sys_get_temp_dir().'/ledgerly-shape-dir-'.uniqid().'/traffic.jsonl';

        TrafficShapeFile::write($nested, [new TrafficShape('support.reply', 10, 5)]);

        $this->assertFileExists($nested);

        unlink($nested);
        rmdir(dirname($nested));
    }

    public function test_a_missing_file_says_how_to_build_it(): void
    {
        $this->expectException(UnusableLoadTest::class);
        $this->expectExceptionMessage('ai:load --export-shape');

        TrafficShapeFile::read($this->path);
    }

    public function test_a_line_without_token_counts_is_unreadable(): void
    {
        file_put_contents($this->path, "{\"feature\":\"support.reply\"}\n");

        $this->expectException(UnusableLoadTest::class);
        $this->expectExceptionMessage('must hold one JSON object per line');

        TrafficShapeFile::read($this->path);
    }

    public function test_an_empty_file_is_unreadable(): void
    {
        file_put_contents($this->path, "\n\n");

        $this->expectException(UnusableLoadTest::class);

        TrafficShapeFile::read($this->path);
    }

    public function test_the_summary_is_the_median_of_each_length(): void
    {
        $summary = ShapeSummary::of([
            new TrafficShape('support.reply', 1000, 100, 400),
            new TrafficShape('support.reply', 2000, 200, 400),
            new TrafficShape('support.reply', 9000, 900, 500),
        ]);

        $this->assertSame(3, $summary->requests);
        $this->assertSame(2000, $summary->inputTokens);
        $this->assertSame(200, $summary->outputTokens);
        $this->assertSame(400, $summary->prefixTokens);
        $this->assertSame('traffic shape: 3 requests · input p50 2000 tokens · output p50 200 tokens · shared prefix p50 400 tokens', $summary->line());
    }

    public function test_percentiles_use_the_nearest_rank(): void
    {
        $values = range(1, 100);

        $this->assertSame(50.0, Percentile::of($values, 50));
        $this->assertSame(99.0, Percentile::of($values, 99));
        $this->assertSame(0.0, Percentile::of([], 99));
        $this->assertSame(7.0, Percentile::of([7], 99));
    }
}
