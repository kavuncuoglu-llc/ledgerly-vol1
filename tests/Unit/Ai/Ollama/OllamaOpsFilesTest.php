<?php

namespace Tests\Unit\Ai\Ollama;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OllamaOpsFilesTest extends TestCase
{
    #[DataProvider('modelfiles')]
    public function test_a_modelfile_pins_num_ctx_to_the_budget_window(string $file): void
    {
        $this->assertSame((int) config('ai.budget.window'), (int) $this->parameter($file, 'num_ctx'));
    }

    public static function modelfiles(): array
    {
        return [
            'ledgerly-support' => ['Modelfile.ledgerly-support'],
            'ledgerly-support-70b' => ['Modelfile.ledgerly-support-70b'],
        ];
    }

    public function test_the_support_modelfile_pins_the_full_base_tag_and_leaves_the_prompt_to_code(): void
    {
        $modelfile = $this->modelfile('Modelfile.ledgerly-support');

        $this->assertMatchesRegularExpression('/^FROM llama3\.1:8b-instruct-q4_K_M$/m', $modelfile);
        $this->assertDoesNotMatchRegularExpression('/^SYSTEM\b/m', $modelfile);
        $this->assertDoesNotMatchRegularExpression('/^TEMPLATE\b/m', $modelfile);
    }

    public function test_the_support_modelfile_matches_the_apps_answer_budget_and_assistant_profile(): void
    {
        $this->assertSame((int) config('ai.budget.answer'), (int) $this->parameter('Modelfile.ledgerly-support', 'num_predict'));
        $this->assertSame((float) config('ai.profiles.assistant.temperature'), (float) $this->parameter('Modelfile.ledgerly-support', 'temperature'));
        $this->assertSame('"<|eot_id|>"', $this->parameter('Modelfile.ledgerly-support', 'stop'));
    }

    public function test_the_support_modelfile_records_the_license(): void
    {
        $this->assertMatchesRegularExpression('/^LICENSE """Llama 3\.1 Community License/m', $this->modelfile('Modelfile.ledgerly-support'));
    }

    public function test_the_ollama_service_is_opt_in_and_only_published_on_loopback(): void
    {
        $service = $this->composeService('ollama');

        $this->assertStringContainsString('profiles: ["linux-gpu"]', $service);
        $this->assertStringContainsString('"127.0.0.1:${OLLAMA_PORT:-11434}:11434"', $service);
        $this->assertDoesNotMatchRegularExpression('/^\s+- "?0\.0\.0\.0:/m', $service);
        $this->assertDoesNotMatchRegularExpression('/^\s+- "?11434:11434/m', $service);
        $this->assertStringContainsString('OLLAMA_NUM_PARALLEL:', $service);
        $this->assertStringContainsString('OLLAMA_MAX_LOADED_MODELS:', $service);
        $this->assertStringContainsString('OLLAMA_KEEP_ALIVE:', $service);
        $this->assertStringContainsString('OLLAMA_FLASH_ATTENTION:', $service);
        $this->assertStringContainsString('driver: nvidia', $service);
        $this->assertStringContainsString('ollama-models:/root/.ollama', $service);
    }

    public function test_the_init_service_builds_the_modelfile_after_ollama_is_healthy(): void
    {
        $service = $this->composeService('ollama-init');

        $this->assertStringContainsString('condition: service_healthy', $service);
        $this->assertStringContainsString('ollama create ledgerly-support -f /modelfiles/Modelfile.ledgerly-support', $service);
        $this->assertStringContainsString('profiles: ["linux-gpu"]', $service);
    }

    public function test_the_composes_parallel_slots_default_matches_the_declared_example(): void
    {
        $this->assertStringContainsString('OLLAMA_NUM_PARALLEL: ${OLLAMA_NUM_PARALLEL:-2}', $this->composeService('ollama'));
    }

    private function modelfile(string $name): string
    {
        return (string) file_get_contents(base_path("ops/ollama/{$name}"));
    }

    private function parameter(string $file, string $name): string
    {
        preg_match('/^PARAMETER '.$name.' (.+)$/m', $this->modelfile($file), $matches);

        return trim($matches[1] ?? '');
    }

    private function composeService(string $name): string
    {
        $compose = (string) file_get_contents(base_path('docker-compose.yml'));

        preg_match('/^  '.preg_quote($name, '/').':\n(?<body>(?:    .*\n|\n)+)/m', $compose, $matches);

        return $matches['body'] ?? '';
    }
}
