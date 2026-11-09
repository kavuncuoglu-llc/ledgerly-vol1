<?php

namespace App\Ai\Drivers\Support\Aws;

final readonly class AwsCredentials
{
    public function __construct(
        public string $accessKey,
        public string $secretKey,
        public ?string $sessionToken = null,
    ) {}
}
