<?php

namespace App\Ai\Tools;

interface Tool
{
    public function name(): string;

    public function description(): string;

    public function arguments(): string;

    public function parameters(): array;

    public function validate(array $arguments): array;

    public function hydrate(array $validated): object;

    public function kind(): ToolKind;

    public function perConversationLimit(): int;

    public function describeIntent(array $arguments, ToolContext $ctx): string;

    public function handle(object $args, ToolContext $ctx): ToolResult;
}
