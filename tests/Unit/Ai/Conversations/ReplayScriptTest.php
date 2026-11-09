<?php

namespace Tests\Unit\Ai\Conversations;

use App\Ai\Conversations\ReplayScript;
use App\Ai\Exceptions\UnreadableReplayScript;
use PHPUnit\Framework\TestCase;

class ReplayScriptTest extends TestCase
{
    public function test_it_reads_the_shipped_thirty_turn_script(): void
    {
        $script = ReplayScript::fromFile(__DIR__.'/../../../../storage/samples/long-conversation.json');

        $this->assertCount(30, $script->messages);
        $this->assertStringContainsString('INV-4471', $script->messages[1]);
        $this->assertStringContainsString('first invoice I mentioned', $script->followUp);
    }

    public function test_it_rejects_a_missing_file(): void
    {
        $this->expectException(UnreadableReplayScript::class);

        ReplayScript::fromFile('/nonexistent/script.json');
    }

    public function test_it_rejects_a_file_without_a_message_list(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'replay');
        file_put_contents($path, '{"messages": []}');

        $this->expectException(UnreadableReplayScript::class);

        try {
            ReplayScript::fromFile($path);
        } finally {
            unlink($path);
        }
    }

    public function test_it_rejects_non_string_messages(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'replay');
        file_put_contents($path, '{"messages": ["ok", 5]}');

        $this->expectException(UnreadableReplayScript::class);

        try {
            ReplayScript::fromFile($path);
        } finally {
            unlink($path);
        }
    }
}
