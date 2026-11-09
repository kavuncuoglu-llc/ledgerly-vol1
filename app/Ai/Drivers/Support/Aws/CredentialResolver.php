<?php

namespace App\Ai\Drivers\Support\Aws;

use App\Ai\Exceptions\AwsCredentialsUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class CredentialResolver
{
    private const METADATA_URL = 'http://169.254.169.254/latest';

    private const TOKEN_TTL_SECONDS = 21600;

    private const REFRESH_MARGIN_SECONDS = 300;

    private const CACHE_KEY = 'ai.aws.instance-role-credentials';

    public function resolve(array $config): AwsCredentials
    {
        if (filled($config['access_key'] ?? null) && filled($config['secret_key'] ?? null)) {
            return new AwsCredentials(
                accessKey: (string) $config['access_key'],
                secretKey: (string) $config['secret_key'],
                sessionToken: filled($config['session_token'] ?? null)
                    ? (string) $config['session_token']
                    : null,
            );
        }

        return $this->fromInstanceRole();
    }

    private function fromInstanceRole(): AwsCredentials
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return new AwsCredentials($cached['access_key'], $cached['secret_key'], $cached['session_token']);
        }

        return $this->fetchFromMetadataService();
    }

    private function fetchFromMetadataService(): AwsCredentials
    {
        try {
            $token = $this->metadata()->withHeaders(['X-aws-ec2-metadata-token-ttl-seconds' => (string) self::TOKEN_TTL_SECONDS])->put(self::METADATA_URL.'/api/token');

            $roles = $this->metadata($token->body())->get(self::METADATA_URL.'/meta-data/iam/security-credentials/')->body();

            $role = trim(explode("\n", trim($roles))[0]);

            $document = $this->metadata($token->body())->get(self::METADATA_URL."/meta-data/iam/security-credentials/{$role}");
        } catch (ConnectionException $exception) {
            throw AwsCredentialsUnavailable::none('The instance metadata service did not answer.', $exception);
        }

        if (! $document->successful()) {
            throw AwsCredentialsUnavailable::none("The instance metadata service answered HTTP {$document->status()}.");
        }

        return $this->remembered($document->json());
    }

    private function remembered(array $document): AwsCredentials
    {
        $credentials = new AwsCredentials(
            accessKey: (string) ($document['AccessKeyId'] ?? ''),
            secretKey: (string) ($document['SecretAccessKey'] ?? ''),
            sessionToken: (string) ($document['Token'] ?? ''),
        );

        if ($credentials->accessKey === '') {
            throw AwsCredentialsUnavailable::none('The instance metadata service sent no access key.');
        }

        $lifetime = (int) now()->diffInSeconds(CarbonImmutable::parse($document['Expiration'] ?? 'now'), false) - self::REFRESH_MARGIN_SECONDS;

        if ($lifetime > 0) {
            Cache::put(self::CACHE_KEY, [
                'access_key' => $credentials->accessKey,
                'secret_key' => $credentials->secretKey,
                'session_token' => $credentials->sessionToken,
            ], $lifetime);
        }

        return $credentials;
    }

    private function metadata(?string $token = null): PendingRequest
    {
        $request = Http::connectTimeout(1)->timeout(2);

        if ($token === null) {
            return $request;
        }

        return $request->withHeaders(['X-aws-ec2-metadata-token' => $token]);
    }
}
