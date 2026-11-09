<?php

namespace App\Ai\Structured;

use App\Ai\Exceptions\EnforcementNotApplied;
use App\Ai\Exceptions\StructuredOutputFailed;
use App\Ai\Exceptions\StructuredRefusal;
use App\Ai\Http\AiHttpClient;
use App\Ai\Schema\SchemaBuilder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidationValidator;
use JsonException;

final class StructuredClient
{
    public function __construct(
        private readonly AiHttpClient $client,
        private readonly SchemaBuilder $schemas,
    ) {}

    public function extract(
        string $class,
        array $messages,
        array $options = [],
        string $feature = 'structured',
        ?string $promptVersion = null,
        string $sourceText = '',
    ): object {
        $options = $this->requestOptions($class, $options);

        $attempts = [];

        foreach ([false, true] as $repaired) {
            $response = $this->client->chat($messages, $options, $feature, $promptVersion, repaired: $repaired);

            $data = $this->decode($class, (string) $response->content);

            $attempts[] = $data;

            $this->assertNotRefused($class, $data, $attempts);

            $validator = $this->validatorFor($class, $data, $sourceText);

            if ($validator->passes()) {
                return $class::fromArray(
                    $validator->validated(),
                    new ExtractionMeta(repaired: $repaired, attempts: count($attempts)),
                );
            }

            $messages = $this->withRepairTurn($messages, (string) $response->content, $validator);
        }

        throw new StructuredOutputFailed($class, $attempts, $validator->errors());
    }

    private function requestOptions(string $class, array $options): array
    {
        return [
            'profile' => 'deterministic',
            ...$options,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $this->schemas->name($class),
                    'strict' => true,
                    'schema' => $this->schemas->for($class),
                ],
            ],
        ];
    }

    private function decode(string $class, string $content): array
    {
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw EnforcementNotApplied::notJson($class, $content, $exception);
        }

        if (! is_array($data)) {
            throw EnforcementNotApplied::notAnObject($class, $content);
        }

        return $data;
    }

    private function assertNotRefused(string $class, array $data, array $attempts): void
    {
        $reason = $data['error'] ?? null;

        if ($reason === null) {
            return;
        }

        throw new StructuredRefusal($class, (string) $reason, $attempts);
    }

    private function validatorFor(string $class, array $data, string $sourceText): ValidationValidator
    {
        $validator = Validator::make($data, $class::rules());

        foreach ($class::afterRules($sourceText) as $after) {
            $validator->after($after);
        }

        return $validator;
    }

    private function withRepairTurn(array $messages, string $invalidJson, ValidationValidator $validator): array
    {
        return [
            ...$messages,
            [
                'role' => 'assistant',
                'content' => $invalidJson,
            ],
            [
                'role' => 'user',
                'content' => RepairPrompt::for($validator->errors()),
            ],
        ];
    }
}
