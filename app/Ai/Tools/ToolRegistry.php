<?php

namespace App\Ai\Tools;

use App\Ai\Exceptions\DuplicateTool;
use App\Ai\Exceptions\UnknownTool;
use Illuminate\Support\Collection;

final class ToolRegistry
{
    private array $nativeTools = [];

    private array $sources = [];

    private ?array $resolvedSourceTools = null;

    public function add(Tool $tool): self
    {
        if (isset($this->nativeTools[$tool->name()])) {
            throw new DuplicateTool($tool->name());
        }

        $this->nativeTools[$tool->name()] = $tool;

        $this->resolvedSourceTools = null;

        return $this;
    }

    public function addSource(ToolSource $source): self
    {
        $this->sources[] = $source;

        $this->resolvedSourceTools = null;

        return $this;
    }

    public function only(array $names): self
    {
        $subset = new self;

        foreach ($this->all() as $tool) {
            if (in_array($tool->name(), $names, true)) {
                $subset->add($tool);
            }
        }

        return $subset;
    }

    public function native(): Collection
    {
        return collect($this->nativeTools)->values();
    }

    public function all(): Collection
    {
        return collect([
            ...$this->nativeTools,
            ...$this->sourceTools(),
        ])->values();
    }

    public function has(string $name): bool
    {
        return $this->all()->contains(fn (Tool $tool): bool => $tool->name() === $name);
    }

    public function get(string $name): Tool
    {
        return $this->all()->first(
            fn (Tool $tool): bool => $tool->name() === $name,
            fn () => throw new UnknownTool($name),
        );
    }

    public function hasHighRiskTools(): bool
    {
        return $this->all()->contains(fn (Tool $tool): bool => $tool->kind() === ToolKind::WriteHighRisk);
    }

    public function definitions(): array
    {
        return $this->all()
            ->sortBy(fn (Tool $tool): string => $tool->name())
            ->values()
            ->map(fn (Tool $tool): array => [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $tool->parameters(),
                ],
            ])
            ->all();
    }

    private function sourceTools(): array
    {
        if ($this->resolvedSourceTools !== null) {
            return $this->resolvedSourceTools;
        }

        $resolved = [];

        foreach ($this->sources as $source) {
            foreach ($source->tools() as $tool) {
                $resolved = $this->withSourceTool($resolved, $tool);
            }
        }

        $this->resolvedSourceTools = $resolved;

        return $resolved;
    }

    private function withSourceTool(array $resolved, Tool $tool): array
    {
        if (isset($this->nativeTools[$tool->name()])) {
            throw new DuplicateTool($tool->name());
        }

        if (isset($resolved[$tool->name()])) {
            throw new DuplicateTool($tool->name());
        }

        $resolved[$tool->name()] = $tool;

        return $resolved;
    }
}
