<?php

namespace App\Ai\Doctor;

final readonly class CheckResult
{
    public function __construct(
        public CheckStatus $status,
        public string $message,
    ) {}

    public static function pass(string $message): self
    {
        return new self(CheckStatus::Pass, $message);
    }

    public static function warn(string $message): self
    {
        return new self(CheckStatus::Warn, $message);
    }

    public static function fail(string $message): self
    {
        return new self(CheckStatus::Fail, $message);
    }

    public function line(): string
    {
        return "{$this->status->symbol()} {$this->message}";
    }
}
