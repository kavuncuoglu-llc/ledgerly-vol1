<?php

namespace Tests\Unit\Ai\Analysis;

use App\Ai\Analysis\BoundaryScanner;
use Tests\TestCase;

class BoundaryScannerTest extends TestCase
{
    public function test_the_application_respects_the_boundary(): void
    {
        $this->assertSame([], (new BoundaryScanner(base_path()))->violations());
    }

    public function test_a_raw_call_in_a_feature_class_is_flagged(): void
    {
        $violations = $this->scan('app/Ai/Triage/Drafter.php', <<<'PHP'
<?php

namespace App\Ai\Triage;

final class Drafter
{
    public function draft(): array
    {
        return $this->model->raw('POST', '/messages/batches', []);
    }
}
PHP);

        $this->assertCount(1, $violations);
        $this->assertSame(BoundaryScanner::RULE_RAW, $violations[0]['rule']);
        $this->assertSame(9, $violations[0]['line']);
    }

    public function test_a_nullsafe_raw_call_and_a_spaced_call_are_flagged_too(): void
    {
        $violations = $this->scan('app/Jobs/Nightly.php', <<<'PHP'
<?php

class Nightly
{
    public function handle(): void
    {
        $this->model?->raw('GET', '/x');
        $this->model->raw ('GET', '/y');
    }
}
PHP);

        $this->assertCount(2, $violations);
    }

    public function test_a_raw_call_under_app_ai_native_is_allowed(): void
    {
        $violations = $this->scan('app/Ai/Native/AnthropicBatches.php', <<<'PHP'
<?php

final class AnthropicBatches
{
    public function create(): array
    {
        return $this->model->raw('POST', '/v1/messages/batches', []);
    }
}
PHP);

        $this->assertSame([], $violations);
    }

    public function test_a_property_or_method_that_only_contains_raw_is_not_a_call(): void
    {
        $violations = $this->scan('app/Ai/Triage/Drafter.php', <<<'PHP'
<?php

final class Drafter
{
    public function draft(): array
    {
        return [$this->rawBody, $this->raw, $this->drawRaw()];
    }
}
PHP);

        $this->assertSame([], $violations);
    }

    public function test_a_driver_class_outside_the_layer_is_flagged_whether_imported_or_written_out(): void
    {
        $violations = $this->scan('app/Ai/Support/SupportAssistant.php', <<<'PHP'
<?php

namespace App\Ai\Support;

use App\Ai\Drivers\AnthropicDriver;

final class SupportAssistant
{
    public function model(): object
    {
        return new \App\Ai\Drivers\OpenAiCompatibleDriver([]);
    }
}
PHP);

        $this->assertCount(2, $violations);
        $this->assertSame(BoundaryScanner::RULE_DRIVER, $violations[0]['rule']);
        $this->assertSame('App\Ai\Drivers\AnthropicDriver', $violations[0]['detail']);
        $this->assertSame('App\Ai\Drivers\OpenAiCompatibleDriver', $violations[1]['detail']);
    }

    public function test_driver_classes_are_allowed_in_the_drivers_the_manager_and_the_fakes(): void
    {
        $source = <<<'PHP'
<?php

use App\Ai\Drivers\FakeDriver;

new FakeDriver([]);
PHP;

        foreach (['app/Ai/Drivers/Other.php', 'app/Ai/AiManager.php', 'app/Ai/Testing/AiFake.php', 'app/Ai/Native/X.php'] as $path) {
            $this->assertSame([], $this->scan($path, $source), $path);
        }
    }

    private function scan(string $path, string $source): array
    {
        return (new BoundaryScanner(base_path()))->violationsIn($path, $source);
    }
}
