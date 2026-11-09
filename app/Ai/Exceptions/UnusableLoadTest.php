<?php

namespace App\Ai\Exceptions;

use RuntimeException;

final class UnusableLoadTest extends RuntimeException
{
    public static function notVllm(string $provider): self
    {
        return new self("Provider [{$provider}] is not a vLLM server: ai:load wraps vllm bench serve, which targets vLLM's OpenAI-compatible endpoint. Pass --provider=vllm.");
    }

    public static function shapeFileMissing(string $path): self
    {
        return new self("Traffic-shape file [{$path}] does not exist. Run ai:load --export-shape to build it from model_calls.");
    }

    public static function shapeFileUnreadable(string $path): self
    {
        return new self("Traffic-shape file [{$path}] must hold one JSON object per line with input_tokens and output_tokens.");
    }

    public static function noTraffic(string $feature, string $since): self
    {
        return new self("No answered model_calls for [{$feature}] in the last {$since} to build a traffic shape from. Use the app (or conversation:replay) first, or widen --since.");
    }

    public static function benchUnavailable(string $binary): self
    {
        return new self("The [{$binary}] command was not found. ai:load runs vllm bench serve on this machine: install the vllm package here (it is only the client side that is used), or pass --vllm-bin=<path>.");
    }

    public static function benchFailed(int $concurrency, int $exitCode, string $output): self
    {
        return new self("vllm bench serve failed at concurrency {$concurrency} (exit {$exitCode}): {$output}");
    }

    public static function resultMissing(int $concurrency, string $path): self
    {
        return new self("vllm bench serve finished at concurrency {$concurrency} but wrote no readable result to [{$path}].");
    }

    public static function tokenizerUnknown(): self
    {
        return new self('vllm bench serve builds its random prompts with the model\'s tokenizer. Set VLLM_MODEL to the Hugging Face repository (or local path) the server loads, or pass --model=.');
    }

    public static function badConcurrency(string $given): self
    {
        return new self("--concurrency must be a comma-separated list of positive whole numbers, got [{$given}].");
    }
}
