<?php

namespace App\Ai\Compare\Cases;

use App\Ai\Compare\CompareCase;
use App\Ai\Compare\Scorers\ExactMatchScorer;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Prompts\TicketClassifyPrompt;
use App\Ai\Schema\SchemaBuilder;
use App\Ai\Schemas\TicketData;
use App\Ai\Support\TicketSamples;

final class TicketClassifyCases
{
    public const FEATURE = 'ticket.classify';

    public function __construct(private readonly SchemaBuilder $schemas) {}

    public function fromFile(string $path, int $limit = 0): BuiltCases
    {
        $samples = Sampler::spread(TicketSamples::fromFile($path), $limit);

        $cases = [];

        foreach ($samples as $index => $sample) {
            $cases[] = new CompareCase(
                id: 'ticket-'.($index + 1),
                feature: self::FEATURE,
                category: $sample['label'],
                request: $this->requestFor($sample['message']),
                scorers: [new ExactMatchScorer($sample['label'])],
            );
        }

        return new BuiltCases($cases);
    }

    private function requestFor(string $message): ChatRequest
    {
        return new ChatRequest(
            messages: TicketClassifyPrompt::messages($message),
            feature: self::FEATURE,
            responseFormat: [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $this->schemas->name(TicketData::class),
                    'strict' => true,
                    'schema' => $this->schemas->for(TicketData::class),
                ],
            ],
            profile: FeatureProfile::of(self::FEATURE),
            promptVersion: TicketClassifyPrompt::VERSION,
        );
    }
}
