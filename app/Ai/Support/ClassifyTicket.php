<?php

namespace App\Ai\Support;

use App\Ai\AiManager;
use App\Ai\Contracts\Capabilities\SupportsGrammar;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\ModelCapabilities;
use App\Ai\Prompts\TicketClassifyPrompt;
use App\Ai\Schemas\TicketData;
use App\Ai\Structured\StructuredClient;
use App\Enums\TicketCategory;

final class ClassifyTicket
{
    private const FEATURE = 'ticket.classify';

    public function __construct(
        private readonly StructuredClient $structured,
        private readonly AiManager $ai,
    ) {}

    public function __invoke(string $message, array $options = []): TicketCategory
    {
        $model = $this->ai->for(self::FEATURE);

        if (ModelCapabilities::has($model, SupportsGrammar::class)) {
            return $this->classifyWithGrammar($model, $message, $options);
        }

        return $this->structured->extract(
            class: TicketData::class,
            messages: TicketClassifyPrompt::messages($message),
            options: $options,
            feature: self::FEATURE,
            promptVersion: TicketClassifyPrompt::VERSION,
        )->category;
    }

    private function classifyWithGrammar(ChatModel $model, string $message, array $options): TicketCategory
    {
        $constrained = ModelCapabilities::withGrammar($model, TicketClassifyPrompt::grammar());

        $response = $constrained->chat(ChatRequest::fromOptions(
            messages: TicketClassifyPrompt::messages($message),
            options: [
                'profile' => 'deterministic',
                ...$options,
                'max_tokens' => TicketClassifyPrompt::GRAMMAR_MAX_TOKENS,
            ],
            feature: self::FEATURE,
            promptVersion: TicketClassifyPrompt::VERSION,
        ));

        return TicketCategory::from(trim((string) $response->content));
    }
}
