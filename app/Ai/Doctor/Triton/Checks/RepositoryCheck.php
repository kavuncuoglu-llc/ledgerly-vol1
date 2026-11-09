<?php

namespace App\Ai\Doctor\Triton\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Triton\TritonCheck;
use App\Ai\Doctor\Triton\TritonDoctorContext;

final class RepositoryCheck implements TritonCheck
{
    public function run(TritonDoctorContext $context): array
    {
        if (! $context->kserve->hasKserveUrl()) {
            return [];
        }

        $index = $context->kserve->repositoryIndex();

        if (! $index->ok()) {
            return [CheckResult::warn("POST /v2/repository/index answered {$index->errorMessage()}: the model states cannot be read.")];
        }

        $states = $this->statesByModel($index->body);

        $results = [];

        foreach ($context->declaredModels() as $model) {
            $results[] = $this->judge($model, $states[$model] ?? []);
        }

        return $results;
    }

    private function statesByModel(array $rows): array
    {
        $states = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $states[(string) ($row['name'] ?? '')][] = [
                'version' => (string) ($row['version'] ?? ''),
                'state' => (string) ($row['state'] ?? ''),
                'reason' => (string) ($row['reason'] ?? ''),
            ];
        }

        return $states;
    }

    private function judge(string $model, array $versions): CheckResult
    {
        if ($versions === []) {
            return CheckResult::fail("model {$model} is not in the repository index: no directory named {$model} under the model repository.");
        }

        $ready = array_filter($versions, fn (array $version): bool => $version['state'] === 'READY');

        if ($ready !== []) {
            return CheckResult::pass("model {$model} READY (version ".implode(', ', array_column($ready, 'version')).')');
        }

        $first = $versions[0];

        return CheckResult::fail("model {$model} is {$first['state']}".($first['reason'] === '' ? '' : ": {$first['reason']}"));
    }
}
