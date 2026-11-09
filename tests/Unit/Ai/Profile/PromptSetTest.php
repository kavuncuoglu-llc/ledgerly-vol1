<?php

namespace Tests\Unit\Ai\Profile;

use App\Ai\Exceptions\UnusableProfile;
use App\Ai\Profile\PromptSet;
use Tests\TestCase;

class PromptSetTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/prompts-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    public function test_strings_and_prompt_objects_are_both_read_and_blanks_dropped(): void
    {
        file_put_contents($this->path, json_encode(['one', ['prompt' => 'two'], '  ', ['other' => 'x']]));

        $this->assertSame(['one', 'two'], PromptSet::fromFile($this->path));
    }

    public function test_the_shipped_prompt_set_is_readable_and_varies_in_length(): void
    {
        $prompts = PromptSet::fromFile(base_path(config('ai.hardware.profile.prompts')));

        $this->assertCount(3, $prompts);
        $this->assertGreaterThan(strlen($prompts[0]) * 10, strlen($prompts[2]));
    }

    public function test_a_missing_file_is_refused(): void
    {
        $this->expectException(UnusableProfile::class);

        PromptSet::fromFile($this->path);
    }

    public function test_a_file_with_no_usable_prompt_is_refused(): void
    {
        file_put_contents($this->path, '["", "  "]');

        $this->expectException(UnusableProfile::class);

        PromptSet::fromFile($this->path);
    }

    public function test_something_that_is_not_json_is_refused(): void
    {
        file_put_contents($this->path, 'not json');

        $this->expectException(UnusableProfile::class);

        PromptSet::fromFile($this->path);
    }
}
