<?php

namespace App\Ai\Support;

use App\Ai\Http\AiHttpClient;
use App\Ai\Prompts\TicketClassifyPrompt;

final class ClassifyTicket
{
    public const FALLBACK_LABEL = 'other';

    public function __construct(private readonly AiHttpClient $client) {}

    public function __invoke(string $message): string
    {
        $label = trim(strtolower((string) $this->client->chat(
            messages: TicketClassifyPrompt::messages($message),
            options: [
                'profile' => 'deterministic',
                'max_tokens' => 4,
                'stop' => ["\n"],
            ],
            feature: 'ticket.classify',
            promptVersion: TicketClassifyPrompt::VERSION,
        )->content));

        return in_array($label, TicketClassifyPrompt::LABELS, true)
            ? $label
            : self::FALLBACK_LABEL;
    }
}
