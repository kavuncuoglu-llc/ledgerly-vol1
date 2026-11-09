<?php

namespace Tests\Feature\Ai\Cache;

use App\Ai\Cache\HelpVersion;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class HelpVersionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/help-version-'.uniqid();

        File::makeDirectory($this->directory.'/billing', recursive: true);
        File::put($this->directory.'/billing/late-fees.md', "# Late fees\nOne and a half percent.");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_current_is_stable_while_the_help_center_does_not_change(): void
    {
        $version = new HelpVersion($this->directory);

        $this->assertSame($version->current(), (new HelpVersion($this->directory))->current());
        $this->assertSame(12, strlen($version->current()));
    }

    public function test_current_changes_when_an_article_is_edited(): void
    {
        $before = (new HelpVersion($this->directory))->current();

        File::put($this->directory.'/billing/late-fees.md', "# Late fees\nTwo percent.");

        $this->assertNotSame($before, (new HelpVersion($this->directory))->current());
    }

    public function test_current_changes_when_an_article_is_added(): void
    {
        $before = (new HelpVersion($this->directory))->current();

        File::put($this->directory.'/billing/refunds.md', '# Refunds');

        $this->assertNotSame($before, (new HelpVersion($this->directory))->current());
    }

    public function test_current_ignores_files_that_are_not_markdown(): void
    {
        $before = (new HelpVersion($this->directory))->current();

        File::put($this->directory.'/notes.txt', 'scratch');

        $this->assertSame($before, (new HelpVersion($this->directory))->current());
    }
}
