<?php

namespace App\Ai\Exceptions;

use Throwable;

final class AwsCredentialsUnavailable extends ModelRequestFailed
{
    public static function none(string $reason, ?Throwable $previous = null): self
    {
        return new self("No AWS credentials: set AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY, or run on an instance with an IAM role. {$reason}", previous: $previous);
    }
}
