<?php

namespace Tests\Unit\Ai\Hardware;

use App\Ai\Exceptions\UnknownModelArchitecture;
use App\Ai\Hardware\ByteSize;
use App\Ai\Hardware\ModelSpecs;
use App\Ai\Hardware\OllamaClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ModelSpecsTest extends TestCase
{
    public function test_a_catalogue_model_is_sized_from_its_parameters_and_quantization(): void
    {
        $spec = (new ModelSpecs)->resolve('llama3.1:70b-instruct-q4_K_M');

        $this->assertSame('q4_k_m', $spec->quantization);
        $this->assertSame(80, $spec->layers);
        $this->assertSame('catalogue', $spec->source);
        $this->assertEqualsWithDelta(70.6 * 0.6, ByteSize::gib($spec->weightsBytes) * 1.073741824, 0.01);
    }

    public function test_an_untagged_model_gets_the_default_quantization(): void
    {
        $spec = (new ModelSpecs)->resolve('llama3.1:8b');

        $this->assertSame('q4_k_m', $spec->quantization);
    }

    public function test_the_quantization_tag_changes_the_weights(): void
    {
        $q4 = (new ModelSpecs)->resolve('llama3.1:70b-instruct-q4_K_M');
        $q8 = (new ModelSpecs)->resolve('llama3.1:70b-instruct-q8_0');
        $fp16 = (new ModelSpecs)->resolve('llama3.1:70b-instruct-fp16');

        $this->assertSame('q8_0', $q8->quantization);
        $this->assertSame('f16', $fp16->quantization);
        $this->assertGreaterThan($q4->weightsBytes, $q8->weightsBytes);
        $this->assertGreaterThan($q8->weightsBytes, $fp16->weightsBytes);
    }

    public function test_an_unknown_model_names_the_options_it_needs(): void
    {
        $this->expectException(UnknownModelArchitecture::class);
        $this->expectExceptionMessage('--layers=, --kv-heads=, --head-dim=, --parameters-billions=');

        (new ModelSpecs)->resolve('mystery:13b');
    }

    public function test_options_describe_a_model_the_catalogue_does_not_know(): void
    {
        $spec = (new ModelSpecs)->resolve('mystery:13b', [
            'parameters_billions' => 13,
            'layers' => 40,
            'kv_heads' => 8,
            'head_dim' => 128,
            'quantization' => 'q8_0',
        ]);

        $this->assertSame('options', $spec->source);
        $this->assertSame((int) round(13 * 1_000_000_000 * 1.06), $spec->weightsBytes);
    }

    public function test_the_server_supplies_the_architecture_and_the_real_file_size(): void
    {
        Http::fake([
            '*/api/show' => Http::response([
                'details' => ['quantization_level' => 'Q4_K_M'],
                'model_info' => [
                    'general.architecture' => 'llama',
                    'llama.block_count' => 32,
                    'llama.attention.head_count' => 32,
                    'llama.attention.head_count_kv' => 8,
                    'llama.embedding_length' => 4096,
                ],
            ]),
            '*/api/tags' => Http::response(['models' => [['name' => 'ledgerly-support:8b', 'size' => 4_920_753_328]]]),
        ]);

        $spec = (new ModelSpecs)->resolve('ledgerly-support:8b', server: new OllamaClient('http://ollama.test'));

        $this->assertSame('server', $spec->source);
        $this->assertSame(4_920_753_328, $spec->weightsBytes);
        $this->assertSame(128, $spec->headDim);
        $this->assertSame(8, $spec->kvHeads);
        $this->assertSame('q4_k_m', $spec->quantization);
    }

    public function test_an_unreachable_server_falls_back_to_the_catalogue(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $spec = (new ModelSpecs)->resolve('llama3.1:8b', server: new OllamaClient('http://ollama.test'));

        $this->assertSame('catalogue', $spec->source);
    }

    public function test_the_server_url_drops_the_openai_compatible_suffix(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        OllamaClient::forProvider(['base_url' => 'http://localhost:11434/v1'])->running();

        Http::assertSent(fn ($request): bool => $request->url() === 'http://localhost:11434/api/ps');
    }
}
