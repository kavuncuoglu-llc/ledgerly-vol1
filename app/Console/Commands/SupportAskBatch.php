<?php

namespace App\Console\Commands;

use App\Ai\Exceptions\EmbeddingFailed;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\ProviderUnavailable;
use App\Ai\Exceptions\UnreadableQuestionBatch;
use App\Ai\Support\QuestionBatch;
use App\Ai\Support\SupportAssistant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Console\Command;

class SupportAskBatch extends Command
{
    private const DEFAULT_USER_EMAIL = 'acme@example.com';

    protected $signature = 'support:ask-batch {path=storage/samples/howto.json : A JSON object with a "questions" list} {--user= : User id or email (default: acme@example.com)}';

    protected $description = 'Ask a file of questions, each in its own conversation, and report how many the semantic cache answered (-v prints one line per question)';

    public function handle(SupportAssistant $assistant, CurrentTenant $tenant): int
    {
        $user = $this->resolveUser();

        if ($user === null) {
            $this->error('No such user.');

            return Command::FAILURE;
        }

        try {
            $batch = QuestionBatch::fromFile($this->absolutePath((string) $this->argument('path')));

            $outcomes = $tenant->runAs($user->customer_id, fn () => $this->askAll($assistant, $user, $batch));
        } catch (UnreadableQuestionBatch|ModelRequestFailed|EmbeddingFailed $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->summarize($outcomes);

        return Command::SUCCESS;
    }

    private function askAll(SupportAssistant $assistant, User $user, QuestionBatch $batch): array
    {
        $outcomes = [];

        foreach ($batch->questions as $index => $question) {
            $started = hrtime(true);

            $result = $assistant->answer($user, $question);

            if ($result->wasDegraded()) {
                throw new ProviderUnavailable($result->degradedReason());
            }

            $outcomes[] = [
                'hit' => isset($result->response->raw['semantic_cache']),
                'ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            ];

            $this->printLine($index + 1, $question, $outcomes[$index]);
        }

        return $outcomes;
    }

    private function printLine(int $number, string $question, array $outcome): void
    {
        if (! $this->getOutput()->isVerbose()) {
            return;
        }

        $source = $outcome['hit']
            ? 'cache'
            : 'model';

        $this->line(sprintf('%2d %-6s %6s ms  %s', $number, $source, number_format($outcome['ms']), $question));
    }

    private function summarize(array $outcomes): void
    {
        $hits = count(array_filter($outcomes, fn (array $outcome): bool => $outcome['hit']));

        $average = (int) round(array_sum(array_column($outcomes, 'ms')) / count($outcomes));

        $this->line(sprintf('%d answered · %d cache hits · avg %s ms', count($outcomes), $hits, number_format($average)));
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
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
