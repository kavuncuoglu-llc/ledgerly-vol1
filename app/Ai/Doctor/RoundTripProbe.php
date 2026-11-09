<?php

namespace App\Ai\Doctor;

use App\Ai\Hardware\OllamaClient;

final class RoundTripProbe
{
    private const PROMPT = 'Reply with the single word: ok';

    public function run(OllamaClient $client, string $model, array $nativeExtras, int $timeoutSeconds): RoundTripOutcome
    {
        $compatible = $client->timedPost('/v1/chat/completions', $this->compatibleBody($model), $timeoutSeconds);

        $runningAfterCompatible = $client->running();

        $native = $client->timedPost('/api/chat', $this->nativeBody($model, $nativeExtras), $timeoutSeconds);

        return new RoundTripOutcome(
            compatible: $compatible,
            native: $native,
            runningAfterCompatible: $runningAfterCompatible,
            runningAfterNative: $client->running(),
        );
    }

    private function compatibleBody(string $model): array
    {
        return [
            'model' => $model,
            'messages' => $this->messages(),
            'max_tokens' => 8,
            'temperature' => 0,
            'stream' => false,
        ];
    }

    private function nativeBody(string $model, array $extras): array
    {
        $options = [
            'num_predict' => 8,
            'temperature' => 0,
        ];

        if (isset($extras['num_ctx'])) {
            $options['num_ctx'] = (int) $extras['num_ctx'];
        }

        $body = [
            'model' => $model,
            'messages' => $this->messages(),
            'stream' => false,
            'options' => $options,
        ];

        if (isset($extras['keep_alive'])) {
            $body['keep_alive'] = $extras['keep_alive'];
        }

        return $body;
    }

    private function messages(): array
    {
        return [
            [
                'role' => 'user',
                'content' => self::PROMPT,
            ],
        ];
    }
}
