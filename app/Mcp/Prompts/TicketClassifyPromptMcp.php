<?php

namespace App\Mcp\Prompts;

use App\Ai\Prompts\TicketClassifyPrompt;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('ticket_classify')]
#[Description('The versioned Ledgerly ticket classification prompt with its few-shot examples, ending in the message to classify.')]
final class TicketClassifyPromptMcp extends Prompt
{
    public function arguments(): array
    {
        return [
            new Argument(
                name: 'message',
                description: 'The support message to classify.',
                required: true,
            ),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $messages = TicketClassifyPrompt::messages((string) $request->get('message'));

        return Response::make(array_map(
            fn (array $message): Response => $this->responseFor($message),
            $messages,
        ));
    }

    private function responseFor(array $message): Response
    {
        $response = Response::text($message['content']);

        if ($message['role'] === 'assistant') {
            return $response->asAssistant();
        }

        return $response;
    }
}
