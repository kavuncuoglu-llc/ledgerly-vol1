<?php

namespace App\Ai\Tools\AccountStatus;

use App\Ai\Schema\Describe;
use App\Ai\Tools\ToolArguments;

final readonly class AccountStatusArgs implements ToolArguments
{
    public function __construct(
        #[Describe('Whether to include the number of open support tickets. Null for yes.')]
        public ?bool $include_open_tickets = null,
    ) {}

    public static function rules(): array
    {
        return [
            'include_open_tickets' => [
                'nullable',
                'boolean',
            ],
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            include_open_tickets: isset($data['include_open_tickets'])
                ? (bool) $data['include_open_tickets']
                : null,
        );
    }
}
