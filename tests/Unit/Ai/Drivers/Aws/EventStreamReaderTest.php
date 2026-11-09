<?php

namespace Tests\Unit\Ai\Drivers\Aws;

use App\Ai\Drivers\Support\Aws\EventStreamReader;
use App\Ai\Exceptions\ModelRequestFailed;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Tests\Support\EventStreamFrames;

class EventStreamReaderTest extends TestCase
{
    public function test_it_reads_headers_and_payload_of_each_frame(): void
    {
        $bytes = EventStreamFrames::event('contentBlockDelta', ['delta' => ['text' => 'hi']]).EventStreamFrames::event('messageStop', ['stopReason' => 'end_turn']);

        $messages = EventStreamReader::parse($bytes);

        $this->assertCount(2, $messages);
        $this->assertSame('contentBlockDelta', $messages[0]['headers'][':event-type']);
        $this->assertSame('event', $messages[0]['headers'][':message-type']);
        $this->assertSame(['delta' => ['text' => 'hi']], json_decode($messages[0]['payload'], true));
        $this->assertSame('messageStop', $messages[1]['headers'][':event-type']);
    }

    public function test_a_stream_is_reassembled_from_chunks_that_split_frames(): void
    {
        $bytes = EventStreamFrames::event('a', ['n' => 1]).EventStreamFrames::event('b', ['n' => 2]);

        $stream = Utils::streamFor($bytes);

        $types = [];

        foreach (EventStreamReader::messages($stream) as $message) {
            $types[] = $message['headers'][':event-type'];
        }

        $this->assertSame(['a', 'b'], $types);
    }

    public function test_a_partial_trailing_frame_is_not_emitted(): void
    {
        $bytes = EventStreamFrames::event('a', ['n' => 1]);

        $messages = EventStreamReader::parse($bytes.substr(EventStreamFrames::event('b', ['n' => 2]), 0, 20));

        $this->assertCount(1, $messages);
    }

    public function test_a_corrupt_frame_is_rejected(): void
    {
        $bytes = EventStreamFrames::event('a', ['n' => 1]);

        $corrupt = substr($bytes, 0, -6).'X'.substr($bytes, -5);

        $this->expectException(ModelRequestFailed::class);

        EventStreamReader::parse($corrupt);
    }

    public function test_non_string_headers_are_skipped_over(): void
    {
        $headers = chr(1).'t'.chr(0).chr(1).'x'.chr(7).pack('n', 1).'y';

        $payload = '{}';

        $total = 12 + strlen($headers) + strlen($payload) + 4;

        $prelude = pack('NN', $total, strlen($headers));

        $withPrelude = $prelude.pack('N', crc32($prelude)).$headers.$payload;

        $messages = EventStreamReader::parse($withPrelude.pack('N', crc32($withPrelude)));

        $this->assertSame(['x' => 'y'], array_intersect_key($messages[0]['headers'], ['x' => true]));
    }
}
