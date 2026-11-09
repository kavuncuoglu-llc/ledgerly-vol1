<?php

namespace Tests\Feature\Console;

use App\Models\CachedAnswer;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\Support\FakesSemanticCache;
use Tests\TestCase;

class SupportAskBatchCommandTest extends TestCase
{
    use FakesSemanticCache;
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.semantic_cache.enabled', true);
        Sleep::fake();
        $this->disableIndexScans();
        $this->path = tempnam(sys_get_temp_dir(), 'howto');
        file_put_contents($this->path, json_encode(['questions' => ['How do I add a client?', 'How do I void an invoice?']]));
    }

    protected function tearDown(): void
    {
        unlink($this->path);

        parent::tearDown();
    }

    public function test_the_first_pass_misses_and_the_second_pass_is_answered_from_the_cache(): void
    {
        $user = User::factory()->forCustomer(Customer::factory()->create())->create();
        $this->fakeSemanticStack([
            'How do I add a client?' => $this->unitVector(0),
            'How do I void an invoice?' => $this->unitVector(1),
        ], answer: 'Open the menu, choose the option and confirm the change.');

        $this->artisan('support:ask-batch', ['path' => $this->path, '--user' => $user->id])
            ->expectsOutputToContain('2 answered · 0 cache hits · avg')
            ->assertSuccessful();

        $this->assertSame(2, CachedAnswer::query()->count());

        $this->artisan('support:ask-batch', ['path' => $this->path, '--user' => $user->id])
            ->expectsOutputToContain('2 answered · 2 cache hits · avg')
            ->assertSuccessful();
    }

    public function test_verbose_prints_one_line_per_question_with_its_source(): void
    {
        $user = User::factory()->forCustomer(Customer::factory()->create())->create();
        $this->fakeSemanticStack([
            'How do I add a client?' => $this->unitVector(0),
            'How do I void an invoice?' => $this->unitVector(1),
        ], answer: 'Open the menu, choose the option and confirm the change.');

        Artisan::call('support:ask-batch', ['path' => $this->path, '--user' => $user->id], new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE));
        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/ 1 model\s+\d+ ms  How do I add a client\?/', $output);
        $this->assertMatchesRegularExpression('/ 2 model\s+\d+ ms  How do I void an invoice\?/', $output);
    }

    public function test_it_fails_on_a_file_it_cannot_read(): void
    {
        $user = User::factory()->forCustomer(Customer::factory()->create())->create();

        $this->artisan('support:ask-batch', ['path' => '/nonexistent/howto.json', '--user' => $user->id])
            ->expectsOutputToContain('does not exist')
            ->assertFailed();
    }

    public function test_it_fails_for_an_unknown_user(): void
    {
        $this->artisan('support:ask-batch', ['path' => $this->path, '--user' => '999999'])
            ->expectsOutput('No such user.')
            ->assertFailed();
    }
}
