<?php

namespace App\Console\Commands;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Contracts\Stream\StreamEvent;
use App\Ai\Contracts\Stream\ToolCallStarted;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\StreamTruncated;
use App\Ai\Exceptions\UnknownSamplingProfile;
use Generator;
use Illuminate\Console\Command;

class AiStream extends Command
{
    protected $signature = 'ai:stream
        {prompt : The user prompt to send}
        {--profile=assistant : Sampling profile from config/ai.php}';

    protected $description = 'Stream a model reply to the terminal as the tokens arrive';

    public function handle(AiManager $ai): int
    {
        try {
            $stream = $ai->for('cli.stream')->stream(ChatRequest::fromOptions(
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
            ));

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
        foreach ($stream as $event) {
            $this->printEvent($event);
        }

        $final = $stream->getReturn();

        $this->newLine();
        $this->line("[{$final->finishReason->value} · in {$final->usage->promptTokens} out {$final->usage->completionTokens}]");

        return Command::SUCCESS;
    }

    private function printEvent(StreamEvent $event): void
    {
        if ($event instanceof ToolCallStarted) {
            $this->line("[calling {$event->name}...]");

            return;
        }

        if ($event instanceof Delta) {
            $this->output->write($event->text);
        }
    }
}
