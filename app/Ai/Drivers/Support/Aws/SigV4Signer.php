<?php

namespace App\Ai\Drivers\Support\Aws;

use DateTimeImmutable;
use DateTimeZone;

final class SigV4Signer
{
    private const ALGORITHM = 'AWS4-HMAC-SHA256';

    public function sign(
        string $method,
        string $host,
        string $path,
        string $body,
        AwsCredentials $credentials,
        string $region,
        string $service,
        DateTimeImmutable $at,
        string $query = '',
    ): array {
        $at = $at->setTimezone(new DateTimeZone('UTC'));

        $amzDate = $at->format('Ymd\THis\Z');

        $headers = $this->headersToSign($host, $amzDate, $credentials);

        $signedHeaders = implode(';', array_keys($headers));

        $scope = "{$at->format('Ymd')}/{$region}/{$service}/aws4_request";

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $this->canonicalPath($path),
            $query,
            $this->canonicalHeaders($headers),
            $signedHeaders,
            hash('sha256', $body),
        ]);

        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($credentials, $at, $region, $service));

        return [
            ...array_diff_key($headers, ['host' => true]),
            'Authorization' => self::ALGORITHM." Credential={$credentials->accessKey}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}",
        ];
    }

    private function headersToSign(string $host, string $amzDate, AwsCredentials $credentials): array
    {
        $headers = [
            'host' => $host,
            'x-amz-date' => $amzDate,
        ];

        if ($credentials->sessionToken !== null) {
            $headers['x-amz-security-token'] = $credentials->sessionToken;
        }

        ksort($headers);

        return $headers;
    }

    private function canonicalHeaders(array $headers): string
    {
        $lines = '';

        foreach ($headers as $name => $value) {
            $lines .= $name.':'.trim($value)."\n";
        }

        return $lines;
    }

    private function canonicalPath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function signingKey(AwsCredentials $credentials, DateTimeImmutable $at, string $region, string $service): string
    {
        $dateKey = hash_hmac('sha256', $at->format('Ymd'), 'AWS4'.$credentials->secretKey, true);

        $regionKey = hash_hmac('sha256', $region, $dateKey, true);

        $serviceKey = hash_hmac('sha256', $service, $regionKey, true);

        return hash_hmac('sha256', 'aws4_request', $serviceKey, true);
    }
}
