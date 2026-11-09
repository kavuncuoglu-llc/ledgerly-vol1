<?php

namespace Tests\Unit\Ai\Sidecar;

use App\Ai\Exceptions\SidecarFailed;
use App\Ai\Retrieval\SidecarReranker;
use App\Ai\Sidecar\SidecarClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SidecarClientTest extends TestCase
{
    public function test_rerank_posts_the_question_and_passages_to_the_configured_url(): void
    {
        Http::fake(['http://sidecar.test:8008/rerank' => Http::response(['scores' => [0.2, 0.9]])]);

        $scores = $this->client()->rerank('late fee?', ['one', 'two']);

        $this->assertSame([0.2, 0.9], $scores);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://sidecar.test:8008/rerank'
            && $request['question'] === 'late fee?'
            && $request['passages'] === ['one', 'two']);
    }

    public function test_rerank_reports_an_unreachable_sidecar(): void
    {
        Http::fake(fn () => throw new ConnectionException('refused'));

        $this->expectException(SidecarFailed::class);
        $this->expectExceptionMessage('docker compose up -d sidecar');

        $this->client()->rerank('q', ['p']);
    }

    public function test_rerank_reports_a_rejected_request(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);

        $this->expectException(SidecarFailed::class);
        $this->expectExceptionMessage('HTTP 500');

        $this->client()->rerank('q', ['p']);
    }

    public function test_rerank_reports_a_body_of_the_wrong_shape(): void
    {
        Http::fake(['*' => Http::response(['unexpected' => true])]);

        $this->expectException(SidecarFailed::class);

        $this->client()->rerank('q', ['p']);
    }

    public function test_a_url_that_is_not_http_is_refused_before_any_request(): void
    {
        Http::fake();

        $client = new SidecarClient('file:///etc/passwd', 5, 30, 25);

        try {
            $client->rerank('q', ['p']);
            $this->fail('Expected SidecarFailed.');
        } catch (SidecarFailed $exception) {
            $this->assertStringContainsString('absolute http or https URL', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_parse_uploads_the_file_and_returns_the_markdown(): void
    {
        Http::fake(['http://sidecar.test:8008/parse' => Http::response(['markdown' => "# Manual\n\nText."])]);
        $path = $this->temporaryFile('pdf', '%PDF-1.4 fake');

        $markdown = $this->client()->parse($path);

        $this->assertSame("# Manual\n\nText.", $markdown);
        Http::assertSent(fn (Request $request): bool => str_contains($request->header('Content-Type')[0], 'multipart/form-data')
            && str_contains($request->body(), '%PDF-1.4 fake'));
    }

    public function test_parse_refuses_a_file_over_the_upload_limit(): void
    {
        Http::fake();
        $path = $this->temporaryFile('pdf', str_repeat('x', 1024 * 1024 + 1));

        try {
            (new SidecarClient('http://sidecar.test:8008', 5, 30, 1))->parse($path);
            $this->fail('Expected SidecarFailed.');
        } catch (SidecarFailed $exception) {
            $this->assertStringContainsString('1 MB sidecar upload limit', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_parse_reports_a_missing_file(): void
    {
        $this->expectException(SidecarFailed::class);

        $this->client()->parse('/no/such/file.pdf');
    }

    public function test_the_sidecar_reranker_rejects_a_score_count_that_does_not_match(): void
    {
        Http::fake(['*' => Http::response(['scores' => [0.5]])]);

        $this->expectException(SidecarFailed::class);

        (new SidecarReranker($this->client()))->score('q', ['a', 'b']);
    }

    public function test_the_sidecar_reranker_makes_no_request_for_no_passages(): void
    {
        Http::fake();

        $scores = (new SidecarReranker($this->client()))->score('q', []);

        $this->assertSame([], $scores);
        Http::assertNothingSent();
    }

    public function test_the_container_builds_the_client_from_config_only(): void
    {
        config(['ai.sidecar.url' => 'http://configured.test:9000']);
        Http::fake(['http://configured.test:9000/rerank' => Http::response(['scores' => [1.0]])]);

        $scores = app(SidecarClient::class)->rerank('q', ['p']);

        $this->assertSame([1.0], $scores);
    }

    private function client(): SidecarClient
    {
        return new SidecarClient('http://sidecar.test:8008', 5, 30, 25);
    }

    private function temporaryFile(string $extension, string $contents): string
    {
        $path = sys_get_temp_dir().'/sidecar-'.uniqid().'.'.$extension;

        File::put($path, $contents);

        $this->beforeApplicationDestroyed(fn () => File::delete($path));

        return $path;
    }
}
