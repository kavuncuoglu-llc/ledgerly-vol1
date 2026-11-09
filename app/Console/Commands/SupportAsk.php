<?php

namespace App\Console\Commands;

use App\Ai\Exceptions\EmbeddingFailed;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Support\SupportAssistant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Console\Command;

class SupportAsk extends Command
{
    private const DEFAULT_USER_EMAIL = 'acme@example.com';

    protected $signature = 'support:ask {question : What the customer asks} {--user= : User id or email (default: acme@example.com)}';

    protected $description = 'Ask the support assistant one question as a customer and say whether the semantic cache answered it';

    public function handle(SupportAssistant $assistant, CurrentTenant $tenant): int
    {
        $user = $this->resolveUser();

        if ($user === null) {
            $this->error('No such user.');

            return Command::FAILURE;
        }

        try {
            $result = $tenant->runAs($user->customer_id, fn () => $assistant->answer($user, (string) $this->argument('question')));
        } catch (ModelRequestFailed|EmbeddingFailed $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->printCacheLine($result->response->raw['semantic_cache'] ?? []);

        $this->line($result->answer());

        return Command::SUCCESS;
    }

    private function printCacheLine(array $hit): void
    {
        if ($hit === []) {
            return;
        }

        $similarity = number_format($hit['similarity'], 2);

        $this->line("[semantic cache hit · {$similarity} · cached question: \"{$hit['cached_question']}\"]");
    }

    private function resolveUser(): ?User
    {
        $given = (string) ($this->option('user') ?: self::DEFAULT_USER_EMAIL);

        if (ctype_digit($given)) {
            return User::find((int) $given);
        }

        return User::query()->where('email', $given)->first();
    }
}
