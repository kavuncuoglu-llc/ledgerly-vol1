<?php

namespace App\Ai\Compare;

use App\Ai\Compare\Scorers\Scorer;
use App\Ai\Contracts\Capability;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;

final readonly class CompareCase
{
    public const FEATURE_PREFIX = 'compare.';

    public function __construct(
        public string $id,
        public string $feature,
        public string $category,
        public ChatRequest $request,
        public array $scorers,
        public bool $sensitive = false,
    ) {}

    public function requestFor(ChatModel $model): ChatRequest
    {
        $request = $this->request->withFeature(self::FEATURE_PREFIX.$this->feature);

        if ($request->responseFormat === null) {
            return $request;
        }

        if ($model->supports(Capability::StructuredOutput)) {
            return $request;
        }

        return $request->withoutResponseFormat();
    }

    public function scorerNames(): array
    {
        return array_map(fn (Scorer $scorer): string => $scorer->name(), $this->scorers);
    }
}
