<?php

namespace App\Ai\Agents;

use App\Ai\Tools\ToolRegistry;
use App\Ai\Tools\ToolResult;

interface Agent
{
    public function name(): string;

    public function feature(): string;

    public function promptVersion(): string;

    public function budget(): AgentBudget;

    public function prompt(AgentState $state): array;

    public function options(): array;

    public function tools(): ToolRegistry;

    public function observe(AgentState $state, array $call, ToolResult $result): AgentState;
}
