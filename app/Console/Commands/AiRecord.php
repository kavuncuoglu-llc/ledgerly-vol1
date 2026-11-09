<?php

namespace App\Console\Commands;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\StreamTruncated;
use App\Ai\Testing\HttpRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;

class AiRecord extends Command
{
    protected $signature = 'ai:record
        {--provider= : Provider name from config/ai.php (default: the default provider)}
        {--fixture= : Fixture name, for example tool-call}
        {--prompt= : A JSON file with messages, and optionally options, tools, response_format and stream}
        {--path= : Directory to write into (default tests/Fixtures/ai)}';

    protected $description = 'Run one request against a real provider and save the raw HTTP exchange, keys redacted, as a fixture';

    public function handle(AiManager $ai, HttpRecorder $recorder): int
    {
        $provider = (string) ($this->option('provider') ?: config('ai.default'));

        $fixture = (string) $this->option('fixture');

        if ($fixture === '') {
            $this->error('Pass --fixture=<name>.');

            return Command::FAILURE;
        }

        try {
            $prompt = $this->prompt();

            $recorder->start();

            $this->send($ai, $provider, $prompt);
        } catch (JsonException|ModelRequestFailed $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        } catch (StreamTruncated) {
            $this->warn('The stream ended early; recording what arrived.');
        }

        if (! $recorder->hasExchange()) {
            $this->error('No HTTP exchange was captured.');

            return Command::FAILURE;
        }

        $this->info('Recorded '.$this->write($ai, $provider, $fixture, $recorder).' (request + response, key redacted)');

        return Command::SUCCESS;
    }

    private function prompt(): array
    {
        $path = (string) $this->option('prompt');

        $decoded = json_decode((string) file_get_contents(base_path($path)), true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    private function send(AiManager $ai, string $provider, array $prompt): void
    {
        $options = [
            ...($prompt['options'] ?? []),
            'tools' => $prompt['tools'] ?? [],
            'response_format' => $prompt['response_format'] ?? null,
        ];

        $request = ChatRequest::fromOptions(
            messages: $prompt['messages'] ?? [],
            options: array_filter($options, fn (mixed $value): bool => $value !== [] && $value !== null),
            feature: 'ai.record',
        );

        $model = $ai->chat($provider);

        if (! ($prompt['stream'] ?? false)) {
            $model->chat($request);

            return;
        }

        iterator_to_array($model->stream($request), false);
    }

    private function write(AiManager $ai, string $provider, string $fixture, HttpRecorder $recorder): string
    {
        $driver = (string) $ai->providerConfig($provider)['driver'];

        $directory = base_path(rtrim((string) ($this->option('path') ?: 'tests/Fixtures/ai'), '/')."/{$driver}");

        File::ensureDirectoryExists($directory);

        $path = "{$directory}/{$fixture}.json";

        File::put($path, json_encode([
            'provider' => $provider,
            'driver' => $driver,
            'recorded_at' => now()->toIso8601String(),
            ...$recorder->exchange(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return str_replace(base_path().'/', '', $path);
    }
}
