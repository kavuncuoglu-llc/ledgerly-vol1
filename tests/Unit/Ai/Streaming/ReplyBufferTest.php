<?php

namespace Tests\Unit\Ai\Streaming;

use App\Ai\Streaming\ReplyBuffer;
use Tests\TestCase;

class ReplyBufferTest extends TestCase
{
    public function test_append_returns_the_sequence_number_of_the_chunk(): void
    {
        $buffer = new ReplyBuffer;

        $first = $buffer->append('r1', 'Hello ');
        $second = $buffer->append('r1', 'world');

        $this->assertSame([0, 1], [$first, $second]);
    }

    public function test_text_joins_the_chunks_in_order(): void
    {
        $buffer = new ReplyBuffer;

        $buffer->append('r1', 'Hello ');
        $buffer->append('r1', 'world');

        $this->assertSame('Hello world', $buffer->text('r1'));
    }

    public function test_after_returns_only_the_chunks_from_the_offset_keyed_by_sequence(): void
    {
        $buffer = new ReplyBuffer;
        $buffer->append('r1', 'a');
        $buffer->append('r1', 'b');
        $buffer->append('r1', 'c');

        $this->assertSame([1 => 'b', 2 => 'c'], $buffer->after('r1', 1));
    }

    public function test_replies_do_not_share_chunks(): void
    {
        $buffer = new ReplyBuffer;

        $buffer->append('r1', 'one');
        $buffer->append('r2', 'two');

        $this->assertSame(['one'], $buffer->chunks('r1'));
    }

    public function test_forget_empties_the_buffer(): void
    {
        $buffer = new ReplyBuffer;
        $buffer->append('r1', 'a');

        $buffer->forget('r1');

        $this->assertSame(0, $buffer->count('r1'));
    }

    public function test_finish_keeps_the_chunks_readable(): void
    {
        $buffer = new ReplyBuffer;
        $buffer->append('r1', 'a');

        $buffer->finish('r1');

        $this->assertSame(['a'], $buffer->chunks('r1'));
    }
}
