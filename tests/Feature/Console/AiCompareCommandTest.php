<?php

namespace Tests\Feature\Console;

use App\Ai\Facades\Ai;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AiCompareCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $output;

    protected function setUp(): void
    {
        parent::setUp();

        $this->output = sys_get_temp_dir().'/compare-'.uniqid();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->output);

        parent::tearDown();
    }

    public function test_it_compares_providers_prints_both_tables_and_saves_the_result(): void
    {
        config()->set('ai.providers.frontier.api_key', 'sk');
        config()->set('ai.providers.frontier.model', 'claude-test');
        config()->set('ai.rates.frontier', ['claude-test' => ['in' => 3.0, 'out' => 15.0]]);
        $this->scriptClassifier(['billing', 'howto', 'bug', 'billing']);

        $this->artisan('ai:compare', ['--providers' => 'local,frontier', '--feature' => 'ticket.classify', '--limit' => 2, '--output' => $this->output])
            ->expectsOutputToContain('ticket.classify: 2 cases x 2 providers')
            ->expectsOutputToContain('quality per $')
            ->expectsOutputToContain('local')
            ->expectsOutputToContain('frontier')
            ->expectsOutputToContain('runs on this machine')
            ->assertSuccessful();

        $saved = $this->savedResult();
        $this->assertSame(['local', 'frontier'], $saved['providers']);
        $this->assertCount(4, $saved['outcomes']);
        $this->assertSame('ticket.classify', $saved['features']['ticket.classify']['providers'][0]['feature']);
        $this->assertNotNull($saved['features']['ticket.classify']['providers'][1]['cost_per_1k']);
    }

    public function test_the_whole_labeled_file_gives_a_per_category_table(): void
    {
        $this->scriptClassifier(array_fill(0, 20, 'billing'));

        $this->artisan('ai:compare', ['--providers' => 'local', '--feature' => 'ticket.classify', '--output' => $this->output])
            ->expectsOutputToContain('by category (quality), ticket.classify')
            ->assertSuccessful();
    }

    public function test_it_needs_providers(): void
    {
        $this->artisan('ai:compare', ['--feature' => 'ticket.classify'])
            ->expectsOutputToContain('Name at least one provider')
            ->assertFailed();
    }

    public function test_it_refuses_a_provider_that_is_not_configured(): void
    {
        $this->artisan('ai:compare', ['--providers' => 'local,frontier', '--feature' => 'ticket.classify'])
            ->expectsOutputToContain('Provider [frontier] is not configured')
            ->assertFailed();
    }

    public function test_it_refuses_an_unknown_provider_and_a_non_chat_provider(): void
    {
        $this->artisan('ai:compare', ['--providers' => 'nope'])->expectsOutputToContain('nope')->assertFailed();
        $this->artisan('ai:compare', ['--providers' => 'rerank'])->expectsOutputToContain('cannot be compared')->assertFailed();
        $this->artisan('ai:compare', ['--providers' => 'local-embed'])->expectsOutputToContain('embedding provider')->assertFailed();
    }

    public function test_golden_needs_exactly_one_feature(): void
    {
        $this->artisan('ai:compare', ['--providers' => 'local', '--golden' => 'x.jsonl'])
            ->expectsOutputToContain('--golden applies to one feature')
            ->assertFailed();
    }

    public function test_replay_without_a_judge_explains_itself(): void
    {
        $this->artisan('ai:compare', ['--providers' => 'local', '--feature' => 'support.reply', '--replay' => true])
            ->expectsOutputToContain('needs --judge')
            ->assertFailed();
    }

    public function test_an_unpriced_hosted_provider_says_no_rate(): void
    {
        config()->set('ai.providers.frontier.api_key', 'sk');
        config()->set('ai.providers.frontier.model', 'claude-test');
        $this->scriptClassifier(['billing']);

        $this->artisan('ai:compare', ['--providers' => 'frontier', '--feature' => 'ticket.classify', '--limit' => 1, '--output' => $this->output])
            ->expectsOutputToContain('no rate')
            ->assertSuccessful();
    }

    public function test_a_second_run_on_the_same_day_does_not_overwrite_the_first(): void
    {
        $this->scriptClassifier(['billing', 'billing']);

        foreach ([1, 2] as $run) {
            $this->artisan('ai:compare', ['--providers' => 'local', '--feature' => 'ticket.classify', '--limit' => 1, '--output' => $this->output])->assertSuccessful();
        }

        $this->assertCount(2, glob($this->output.'/compare-*.json'));
    }

    private function scriptClassifier(array $categories): void
    {
        $script = Ai::fake();

        foreach ($categories as $category) {
            $script->for('compare.ticket.classify')->respondWithJson(['category' => $category, 'invoice_number' => null, 'customer_email' => null, 'amount_mentioned' => null, 'date_mentioned' => null]);
        }
    }

    private function savedResult(): array
    {
        $files = glob($this->output.'/compare-*.json');

        $this->assertCount(1, $files);

        return json_decode((string) file_get_contents($files[0]), true);
    }
}
