<?php

namespace Tests\Unit\Ai\Profile;

use App\Ai\Hardware\OllamaClient;
use App\Ai\Profile\OllamaMemoryProbe;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeHost;
use Tests\TestCase;

class OllamaMemoryProbeTest extends TestCase
{
    public function test_on_linux_resident_memory_is_the_rss_of_the_ollama_processes_only(): void
    {
        $host = new FakeHost('Linux', [
            'ps -axo rss=,comm=' => "  2048000 /usr/local/bin/ollama\n   512000 /usr/lib/ollama/runner\n 90000000 /usr/bin/postgres\n  1000 /opt/Ollama.app/ollama-helper",
        ]);
        Http::fake(['*/api/ps' => Http::response(['models' => []])]);

        $sample = $this->probe($host)->read();

        $this->assertSame((2_048_000 + 1000) * 1024, $sample->residentBytes);
    }

    public function test_on_a_mac_resident_memory_is_the_wired_memory_from_vm_stat(): void
    {
        $host = new FakeHost('Darwin', [
            'vm_stat' => "Mach Virtual Memory Statistics: (page size of 16384 bytes)\nPages free: 5.\nPages wired down:                    700000.\n",
        ]);
        Http::fake(['*/api/ps' => Http::response(['models' => []])]);

        $probe = $this->probe($host);

        $this->assertSame(700_000 * 16_384, $probe->read()->residentBytes);
        $this->assertStringContainsString('wired', $probe->residentSource());
    }

    public function test_gpu_and_spilled_memory_come_from_the_servers_running_models(): void
    {
        Http::fake(['*/api/ps' => Http::response(['models' => [
            ['name' => 'llama3.1:8b', 'size' => 9_000_000_000, 'size_vram' => 6_000_000_000],
        ]])]);

        $sample = $this->probe(new FakeHost('Linux'), 'llama3.1:8b')->read();

        $this->assertSame(6_000_000_000, $sample->gpuBytes);
        $this->assertSame(3_000_000_000, $sample->spilledBytes);
        $this->assertTrue($sample->modelLoaded);
    }

    public function test_another_loaded_model_does_not_count_as_ours(): void
    {
        Http::fake(['*/api/ps' => Http::response(['models' => [['name' => 'other:1b', 'size' => 1, 'size_vram' => 1]]])]);

        $this->assertFalse($this->probe(new FakeHost('Linux'), 'llama3.1:8b')->read()->modelLoaded);
    }

    public function test_a_server_that_cannot_be_reached_reads_as_nothing_loaded(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $sample = $this->probe(new FakeHost('Linux'))->read();

        $this->assertFalse($sample->modelLoaded);
        $this->assertSame(0, $sample->gpuBytes);
    }

    private function probe(FakeHost $host, string $model = ''): OllamaMemoryProbe
    {
        return new OllamaMemoryProbe($host, new OllamaClient('http://ollama.test'), $model);
    }
}
