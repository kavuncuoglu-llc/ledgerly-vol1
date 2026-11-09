<?php

namespace App\Console\Commands;

use App\Ai\AiManager;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Exceptions\UnknownProvider;
use App\Ai\Health\WarmResult;
use App\Ai\Health\WarmUp;
use Illuminate\Console\Command;

class AiWarm extends Command
{
    protected $signature = 'ai:warm
        {--provider= : Provider to warm (default: the provider the support.reply feature uses)}
        {--wait=0 : Seconds to wait for the server to start answering before giving up}';

    protected $description = 'Send the support prompt to every model a provider serves for this app, twice, so the first customer does not pay the load, compile and prefix-cache cost';

    public function handle(AiManager $ai, WarmUp $warmUp): int
    {
        $provider = (string) ($this->option('provider') ?: $ai->providerNameFor('support.reply'));

        try {
            $models = $warmUp->models($provider);
        } catch (UnknownProvider $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $ready = $warmUp->waitUntilReady($provider, (int) $this->option('wait'));

        if (! $ready->ok()) {
            $this->error("Provider {$provider} is not ready: {$ready->errorMessage()}. Start the server, or pass --wait=<seconds> to wait for it.");

            return Command::FAILURE;
        }

        return $this->warmEach($warmUp, $provider, $models);
    }

    private function warmEach(WarmUp $warmUp, string $provider, array $models): int
    {
        $total = 0.0;

        foreach ($models as $model) {
            try {
                $result = $warmUp->run($provider, $model);
            } catch (ModelRequestFailed $exception) {
                $this->error("{$model}: could not be warmed: {$exception->getMessage()}");

                return Command::FAILURE;
            }

            $total += $result->firstSeconds + $result->warmSeconds;

            $this->line($this->describe($result));
        }

        $warmUp->remember($provider, $models, $total);

        return Command::SUCCESS;
    }

    private function describe(WarmResult $result): string
    {
        return sprintf(
            '%s: warm (%.2f s; the first call took %.2f s) · %s',
            $result->model,
            $result->warmSeconds,
            $result->firstSeconds,
            $this->prefixNote($result),
        );
    }

    private function prefixNote(WarmResult $result): string
    {
        if ($result->cachedTokens === null) {
            return "prefix cache not reported by the server ({$result->promptTokens} prompt tokens)";
        }

        if ($result->cachedTokens === 0) {
            return "no prompt tokens served from the prefix cache ({$result->promptTokens} prompt tokens)";
        }

        return "prefix cached: {$result->cachedTokens} of {$result->promptTokens} prompt tokens";
    }
}
