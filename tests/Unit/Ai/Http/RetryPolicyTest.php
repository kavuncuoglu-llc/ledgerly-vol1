<?php

namespace Tests\Unit\Ai\Http;

use App\Ai\Http\RetryPolicy;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Tests\TestCase;

class RetryPolicyTest extends TestCase
{
    public function test_is_retryable_accepts_a_dead_connection(): void
    {
        $retryable = (new RetryPolicy)->isRetryable(new ConnectionException('refused'));

        $this->assertTrue($retryable);
    }

    public function test_is_retryable_accepts_the_transient_status_codes(): void
    {
        foreach ([408, 409, 425, 429, 500, 502, 503, 504, 529] as $status) {
            $this->assertTrue((new RetryPolicy)->isRetryable($this->failure($status)), "status {$status}");
        }
    }

    public function test_is_retryable_rejects_the_callers_own_mistakes(): void
    {
        foreach ([400, 401, 403, 404, 422] as $status) {
            $this->assertFalse((new RetryPolicy)->isRetryable($this->failure($status)), "status {$status}");
        }
    }

    public function test_is_retryable_rejects_unrelated_exceptions(): void
    {
        $retryable = (new RetryPolicy)->isRetryable(new RuntimeException('boom'));

        $this->assertFalse($retryable);
    }

    public function test_delay_milliseconds_doubles_each_attempt_with_up_to_250_ms_of_jitter(): void
    {
        $policy = new RetryPolicy;

        foreach ([1 => 500, 2 => 1000, 3 => 2000] as $attempt => $base) {
            $delay = $policy->delayMilliseconds($attempt, new ConnectionException('refused'));

            $this->assertGreaterThanOrEqual($base, $delay);
            $this->assertLessThanOrEqual($base + 250, $delay);
        }
    }

    public function test_delay_milliseconds_uses_retry_after_seconds_when_present(): void
    {
        $delay = (new RetryPolicy)->delayMilliseconds(1, $this->failure(429, ['Retry-After' => '3']));

        $this->assertSame(3000, $delay);
    }

    public function test_delay_milliseconds_ignores_a_retry_after_that_is_not_a_number(): void
    {
        $delay = (new RetryPolicy)->delayMilliseconds(1, $this->failure(503, ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT']));

        $this->assertGreaterThanOrEqual(500, $delay);
        $this->assertLessThanOrEqual(750, $delay);
    }

    private function failure(int $status, array $headers = []): RequestException
    {
        return new RequestException(new Response(new PsrResponse($status, $headers)));
    }
}
