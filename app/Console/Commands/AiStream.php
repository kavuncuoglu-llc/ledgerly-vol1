<?php

namespace App\Console\Commands;

use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\StreamTruncated;
use App\Ai\Exceptions\UnknownSamplingProfile;
use App\Ai\Http\AiHttpClient;
use App\Ai\Http\ToolCallStarted;
use Generator;
use Illuminate\Console\Command;

class AiStream extends Command
{
    protected $signature = 'ai:stream
        {prompt : The user prompt to send}
        {--profile=assistant : Sampling profile from config/ai.php}';

    protected $description = 'Stream a model reply to the terminal as the tokens arrive';

    public function handle(AiHttpClient $client): int
    {
        $stream = $client->stream(
            messages: [
                [
                    'role' => 'user',
                    'content' => (string) $this->argument('prompt'),
                ],
            ],
            options: [
                'profile' => (string) $this->option('profile'),
            ],
            feature: 'cli.stream',
        );

        try {
            return $this->printStream($stream);
        } catch (StreamTruncated) {
            $this->newLine();
            $this->warn('[stream cut off before completion]');

            return Command::FAILURE;
        } catch (UnknownSamplingProfile $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        } catch (ModelRequestFailed $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }
    }

    private function printStream(Generator $stream): int
    {
        foreach ($stream as $delta) {
            $this->printDelta($delta);
        }

        $final = $stream->getReturn();

        $this->newLine();
        $this->line("[{$final->finishReason} · in {$final->usage->promptTokens} out {$final->usage->completionTokens}]");

        return Command::SUCCESS;
    }

    private function printDelta(string|ToolCallStarted $delta): void
    {
        if ($delta instanceof ToolCallStarted) {
            $this->line("[calling {$delta->name}...]");

            return;
        }

        $this->output->write($delta);
    }
}
