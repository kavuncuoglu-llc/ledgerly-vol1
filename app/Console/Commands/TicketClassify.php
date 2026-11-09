<?php

namespace App\Console\Commands;

use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\StructuredOutputFailed;
use App\Ai\Exceptions\StructuredRefusal;
use App\Ai\Exceptions\UnreadableTicketSamples;
use App\Ai\Support\ClassifyTicket;
use App\Ai\Support\TicketSamples;
use Illuminate\Console\Command;

class TicketClassify extends Command
{
    protected $signature = 'ticket:classify {path=storage/samples/tickets.json : A JSON list of {message, label} objects}';

    protected $description = 'Classify labeled sample support messages and report agreement with the human labels';

    public function handle(ClassifyTicket $classify): int
    {
        try {
            $samples = TicketSamples::fromFile($this->absolutePath((string) $this->argument('path')));

            $misses = $this->collectMisses($classify, $samples);
        } catch (UnreadableTicketSamples|ModelRequestFailed|StructuredOutputFailed|StructuredRefusal $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->report(count($samples), $misses);

        return Command::SUCCESS;
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
    }

    private function collectMisses(ClassifyTicket $classify, array $samples): array
    {
        $misses = [];

        foreach ($samples as $sample) {
            $modelLabel = $classify($sample['message'])->value;

            if ($modelLabel === $sample['label']) {
                continue;
            }

            $misses[] = [
                'message' => $sample['message'],
                'model' => $modelLabel,
                'human' => $sample['label'],
            ];
        }

        return $misses;
    }

    private function report(int $total, array $misses): void
    {
        $agreed = $total - count($misses);

        $percent = $total === 0
            ? 0
            : (int) round($agreed / $total * 100);

        $this->line("{$agreed}/{$total} agree with human labels ({$percent}%)");

        if ($misses === []) {
            return;
        }

        $this->line('misses:');

        foreach ($misses as $miss) {
            $this->line("  \"{$miss['message']}\" model: {$miss['model']} human: {$miss['human']}");
        }
    }
}
