<?php

namespace App\Ai\Doctor\Llama\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Llama\LlamaCheck;
use App\Ai\Doctor\Llama\LlamaDoctorContext;
use App\Ai\Prompts\TicketClassifyPrompt;

final class GrammarCheck implements LlamaCheck
{
    public function run(LlamaDoctorContext $context): array
    {
        $messages = TicketClassifyPrompt::messages('Why was I charged a late fee on invoice 4471?');

        $template = $context->client->applyTemplate($messages, $context->probeTimeoutSeconds);

        $prompt = $template->body['prompt'] ?? null;

        if (! $template->ok()) {
            return [CheckResult::fail("/apply-template failed: {$template->errorMessage()}. The grammar classifier renders its prompt there; this build of llama-server may predate it.")];
        }

        if (! is_string($prompt)) {
            return [CheckResult::fail('/apply-template answered without a prompt string, so the grammar classifier cannot render its messages.')];
        }

        $completion = $context->client->completion([
            'prompt' => $prompt,
            'grammar' => TicketClassifyPrompt::grammar(),
            'temperature' => 0,
            'n_predict' => TicketClassifyPrompt::GRAMMAR_MAX_TOKENS,
            'stream' => false,
        ], $context->probeTimeoutSeconds);

        if (! $completion->ok()) {
            return [CheckResult::fail("/completion with a grammar failed: {$completion->errorMessage()}")];
        }

        $label = trim((string) ($completion->body['content'] ?? ''));

        if (! in_array($label, TicketClassifyPrompt::LABELS, true)) {
            return [CheckResult::fail("/completion with the label grammar answered [{$label}], which is not one of the five labels: the grammar was not applied.")];
        }

        return [CheckResult::pass("grammar-constrained /completion answered [{$label}] in {$this->seconds($completion->milliseconds)}")];
    }

    private function seconds(int $milliseconds): string
    {
        return number_format($milliseconds / 1000, 1).' s';
    }
}
