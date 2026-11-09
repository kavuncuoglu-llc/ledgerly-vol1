<?php

namespace App\Mcp\Prompts;

use App\Ai\Prompts\SupportPrompt;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('support_assistant')]
#[Description('The versioned Ledgerly support assistant prompt: system rules followed by the customer\'s question.')]
final class SupportPromptMcp extends Prompt
{
    public function arguments(): array
    {
        return [
            new Argument(
                name: 'question',
                description: 'The customer\'s question.',
                required: true,
            ),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $messages = SupportPrompt::messages((string) $request->get('question'));

        return Response::make(array_map(
            fn (array $message): Response => Response::text($message['content']),
            $messages,
        ));
    }
}
