<?php

namespace App\Ai\Decorators;

use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Exceptions\UnknownSamplingProfile;
use Generator;

final class ProfileMergingModel extends ChatModelDecorator
{
    private const DEFAULT_PROFILE = 'assistant';

    public function withInner(ChatModel $inner): static
    {
        return new self($inner);
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        return $this->inner->chat($this->merged($request));
    }

    public function stream(ChatRequest $request): Generator
    {
        return $this->inner->stream($this->merged($request));
    }

    private function merged(ChatRequest $request): ChatRequest
    {
        return $this->routed($this->profiled($request));
    }

    private function routed(ChatRequest $request): ChatRequest
    {
        if ($request->model !== null) {
            return $request;
        }

        $feature = config('ai.features', [])[$request->feature] ?? [];

        if (($feature['provider'] ?? null) !== $this->inner->name()) {
            return $request;
        }

        if (blank($feature['model'] ?? null)) {
            return $request;
        }

        return $request->withModel((string) $feature['model']);
    }

    private function profiled(ChatRequest $request): ChatRequest
    {
        $name = $request->profile ?? $this->featureProfile($request->feature) ?? self::DEFAULT_PROFILE;

        if ($name === ChatRequest::NO_PROFILE) {
            return $request->withProfileApplied($request->options);
        }

        $profile = config("ai.profiles.{$name}");

        if (! is_array($profile)) {
            throw new UnknownSamplingProfile($name);
        }

        return $request->withProfileApplied([...$profile, ...$request->options]);
    }

    private function featureProfile(string $feature): ?string
    {
        return config('ai.features', [])[$feature]['profile'] ?? null;
    }
}
