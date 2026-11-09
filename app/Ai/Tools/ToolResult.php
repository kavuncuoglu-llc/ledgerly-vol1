<?php

namespace App\Ai\Tools;

final readonly class ToolResult
{
    public const STATUS_OK = 'ok';

    public const STATUS_ERROR = 'error';

    public const STATUS_DENIED = 'denied';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_PENDING = 'pending';

    public const MODEL_DENIAL = 'not permitted';

    public const MODEL_DECLINE = 'declined by user';

    private function __construct(
        public bool $ok,
        public string $status,
        public array $data = [],
        public string $error = '',
        public array $details = [],
        public string $reason = '',
        public string $pendingId = '',
        public string $summary = '',
        public string $approvalUrl = '',
        public string $text = '',
    ) {}

    public static function ok(array $data = []): self
    {
        return new self(
            ok: true,
            status: self::STATUS_OK,
            data: $data,
        );
    }

    public static function text(string $text): self
    {
        return new self(
            ok: true,
            status: self::STATUS_OK,
            text: $text,
        );
    }

    public static function error(string $error, array $details = []): self
    {
        return new self(
            ok: false,
            status: self::STATUS_ERROR,
            error: $error,
            details: $details,
        );
    }

    public static function denied(string $reason): self
    {
        return new self(
            ok: false,
            status: self::STATUS_DENIED,
            error: self::MODEL_DENIAL,
            reason: $reason,
        );
    }

    public static function declined(): self
    {
        return new self(
            ok: false,
            status: self::STATUS_DECLINED,
            error: self::MODEL_DECLINE,
        );
    }

    public static function pending(string $pendingId, string $summary, string $approvalUrl): self
    {
        return new self(
            ok: false,
            status: self::STATUS_PENDING,
            error: 'awaiting approval',
            pendingId: $pendingId,
            summary: $summary,
            approvalUrl: $approvalUrl,
        );
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isText(): bool
    {
        return $this->text !== '';
    }

    public function toArray(): array
    {
        if ($this->isText()) {
            return [
                'ok' => true,
                'text' => $this->text,
            ];
        }

        if ($this->ok) {
            return [
                'ok' => true,
                ...$this->data,
            ];
        }

        $payload = [
            'ok' => false,
            'error' => $this->error,
        ];

        if ($this->details === []) {
            return $payload;
        }

        return [
            ...$payload,
            'details' => $this->details,
        ];
    }

    public function auditArray(): array
    {
        if ($this->reason === '') {
            return $this->toArray();
        }

        return [
            ...$this->toArray(),
            'reason' => $this->reason,
        ];
    }

    public function toJson(): string
    {
        if ($this->isText()) {
            return $this->text;
        }

        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
