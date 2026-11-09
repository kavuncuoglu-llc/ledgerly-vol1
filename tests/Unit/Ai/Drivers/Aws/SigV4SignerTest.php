<?php

namespace Tests\Unit\Ai\Drivers\Aws;

use App\Ai\Drivers\Support\Aws\AwsCredentials;
use App\Ai\Drivers\Support\Aws\SigV4Signer;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class SigV4SignerTest extends TestCase
{
    private const EXAMPLE_KEY = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

    public function test_it_reproduces_the_published_get_vanilla_signature(): void
    {
        $headers = (new SigV4Signer)->sign(
            method: 'GET',
            host: 'example.amazonaws.com',
            path: '/',
            body: '',
            credentials: new AwsCredentials('AKIDEXAMPLE', self::EXAMPLE_KEY),
            region: 'us-east-1',
            service: 'service',
            at: new DateTimeImmutable('2015-08-30 12:36:00', new DateTimeZone('UTC')),
        );

        $this->assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
            $headers['Authorization'],
        );
        $this->assertSame('20150830T123600Z', $headers['x-amz-date']);
    }

    public function test_a_session_token_is_signed_and_returned(): void
    {
        $headers = (new SigV4Signer)->sign(
            method: 'POST',
            host: 'bedrock-runtime.us-east-1.amazonaws.com',
            path: '/model/m/converse',
            body: '{}',
            credentials: new AwsCredentials('AKID', 'secret', 'session-token'),
            region: 'us-east-1',
            service: 'bedrock',
            at: new DateTimeImmutable('2026-11-09 10:00:00', new DateTimeZone('UTC')),
        );

        $this->assertSame('session-token', $headers['x-amz-security-token']);
        $this->assertStringContainsString('SignedHeaders=host;x-amz-date;x-amz-security-token', $headers['Authorization']);
    }

    public function test_the_body_changes_the_signature(): void
    {
        $sign = fn (string $body): string => (new SigV4Signer)->sign('POST', 'h.example.com', '/p', $body, new AwsCredentials('AKID', 'secret'), 'us-east-1', 'bedrock', new DateTimeImmutable('2026-11-09 10:00:00', new DateTimeZone('UTC')))['Authorization'];

        $this->assertNotSame($sign('{"a":1}'), $sign('{"a":2}'));
        $this->assertSame($sign('{"a":1}'), $sign('{"a":1}'));
    }

    public function test_a_percent_encoded_path_is_encoded_again_in_the_canonical_request(): void
    {
        $encoded = (new SigV4Signer)->sign('POST', 'h.example.com', '/model/a%3A0/converse', '{}', new AwsCredentials('AKID', 'secret'), 'us-east-1', 'bedrock', new DateTimeImmutable('2026-11-09 10:00:00', new DateTimeZone('UTC')))['Authorization'];

        $plain = (new SigV4Signer)->sign('POST', 'h.example.com', '/model/a:0/converse', '{}', new AwsCredentials('AKID', 'secret'), 'us-east-1', 'bedrock', new DateTimeImmutable('2026-11-09 10:00:00', new DateTimeZone('UTC')))['Authorization'];

        $this->assertNotSame($encoded, $plain);
    }
}
