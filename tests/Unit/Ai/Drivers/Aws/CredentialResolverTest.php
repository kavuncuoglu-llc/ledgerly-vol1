<?php

namespace Tests\Unit\Ai\Drivers\Aws;

use App\Ai\Drivers\Support\Aws\CredentialResolver;
use App\Ai\Exceptions\AwsCredentialsUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CredentialResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_configured_keys_are_used_without_touching_the_network(): void
    {
        Http::fake();

        $credentials = (new CredentialResolver)->resolve([
            'access_key' => 'AKID',
            'secret_key' => 'secret',
            'session_token' => 'token',
        ]);

        $this->assertSame('AKID', $credentials->accessKey);
        $this->assertSame('secret', $credentials->secretKey);
        $this->assertSame('token', $credentials->sessionToken);
        Http::assertNothingSent();
    }

    public function test_without_keys_it_asks_the_instance_metadata_service_with_a_session_token(): void
    {
        $this->fakeMetadata();

        $credentials = (new CredentialResolver)->resolve([]);

        $this->assertSame('ASIAROLE', $credentials->accessKey);
        $this->assertSame('role-secret', $credentials->secretKey);
        $this->assertSame('role-token', $credentials->sessionToken);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request->hasHeader('X-aws-ec2-metadata-token-ttl-seconds'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && str_ends_with($request->url(), '/security-credentials/ledgerly-role') && $request->hasHeader('X-aws-ec2-metadata-token', 'imds-token'));
    }

    public function test_instance_role_credentials_are_cached_until_shortly_before_they_expire(): void
    {
        $this->fakeMetadata();

        (new CredentialResolver)->resolve([]);
        (new CredentialResolver)->resolve([]);

        Http::assertSentCount(3);
    }

    public function test_an_unreachable_metadata_service_is_a_clear_error(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->expectException(AwsCredentialsUnavailable::class);
        $this->expectExceptionMessage('AWS_ACCESS_KEY_ID');

        (new CredentialResolver)->resolve([]);
    }

    private function fakeMetadata(): void
    {
        Http::fake([
            '*/api/token' => Http::response('imds-token'),
            '*/security-credentials/' => Http::response("ledgerly-role\n"),
            '*/security-credentials/ledgerly-role' => Http::response([
                'AccessKeyId' => 'ASIAROLE',
                'SecretAccessKey' => 'role-secret',
                'Token' => 'role-token',
                'Expiration' => now()->addHours(6)->toIso8601ZuluString(),
            ]),
        ]);
    }
}
