<?php

namespace App\Ai\Tools;

use App\Ai\Schema\SchemaBuilder;
use Illuminate\Support\Facades\Validator;

trait DtoBackedTool
{
    public function parameters(): array
    {
        return SchemaBuilder::for($this->arguments());
    }

    public function validate(array $arguments): array
    {
        $argumentsClass = $this->arguments();

        return Validator::make($arguments, $argumentsClass::rules())->validate();
    }

    public function hydrate(array $validated): object
    {
        $argumentsClass = $this->arguments();

        return $argumentsClass::fromArray($validated);
    }

    public function perConversationLimit(): int
    {
        return 10;
    }

    public function describeIntent(array $arguments, ToolContext $ctx): string
    {
        return $this->name().' '.json_encode($arguments);
    }
}
