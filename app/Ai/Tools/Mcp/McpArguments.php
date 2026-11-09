<?php

namespace App\Ai\Tools\Mcp;

use App\Ai\Tools\ToolArguments;

final readonly class McpArguments implements ToolArguments
{
    public function __construct(public array $values) {}

    public static function rules(): array
    {
        return [];
    }

    public static function fromArray(array $data): static
    {
        return new self($data);
    }
}
