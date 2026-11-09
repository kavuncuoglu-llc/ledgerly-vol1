<?php

namespace App\Ai\Tools;

interface ToolArguments
{
    public static function rules(): array;

    public static function fromArray(array $data): static;
}
