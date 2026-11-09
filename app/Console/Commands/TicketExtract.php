<?php

namespace App\Console\Commands;

use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\StructuredOutputFailed;
use App\Ai\Exceptions\StructuredRefusal;
use App\Ai\Exceptions\UnreadableTicketSamples;
use App\Ai\Prompts\TicketExtractPrompt;
use App\Ai\Schemas\TicketData;
use App\Ai\Structured\StructuredClient;
use App\Ai\Support\TicketSamples;
use Illuminate\Console\Command;

class TicketExtract extends Command
{
    private const NULLABLE_FIELDS = [
        'invoice_number',
        'customer_email',
        'amount_mentioned',
        'date_mentioned',
    ];

    protected $signature = 'ticket:extract {path=storage/samples/tickets.json : A JSON list of {message, label} objects}';

    protected $description = 'Extract invoice number, email, amount, and date from sample support messages and report how many fields were filled';

    public function handle(StructuredClient $structured): int
    {
        try {
            $samples = TicketSamples::fromFile($this->absolutePath((string) $this->argument('path')));

            $tickets = $this->extractAll($structured, $samples);
        } catch (UnreadableTicketSamples|ModelRequestFailed $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->report(count($samples), $tickets);

        return Command::SUCCESS;
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
    }

    private function extractAll(StructuredClient $structured, array $samples): array
    {
        $results = [];

        foreach ($samples as $sample) {
            $results = [...$results, ...$this->extractOne($structured, $sample['message'])];
        }

        return $results;
    }

    private function extractOne(StructuredClient $structured, string $message): array
    {
        try {
            return [
                $structured->extract(
                    class: TicketData::class,
                    messages: TicketExtractPrompt::messages($message),
                    feature: 'ticket.extract',
                    promptVersion: TicketExtractPrompt::VERSION,
                ),
            ];
        } catch (StructuredOutputFailed|StructuredRefusal $exception) {
            $this->warn($exception->getMessage());

            return [];
        }
    }

    private function report(int $total, array $tickets): void
    {
        $repaired = count(array_filter($tickets, fn (TicketData $ticket): bool => $ticket->meta->repaired));

        $this->line(sprintf('%d/%d valid · %d repaired · %d failed', count($tickets), $total, $repaired, $total - count($tickets)));

        $this->line($this->fillCounts($tickets));
    }

    private function fillCounts(array $tickets): string
    {
        $counts = [];

        foreach (self::NULLABLE_FIELDS as $field) {
            $filled = count(array_filter($tickets, fn (TicketData $ticket): bool => $ticket->{$field} !== null));

            $counts[] = "{$field} filled: {$filled}";
        }

        return implode(' ', $counts);
    }
}
