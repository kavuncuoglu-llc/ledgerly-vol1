<?php

namespace Tests\Feature\Ai\Tools;

use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRunner;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\FakesToolModel;
use Tests\TestCase;

class ToolRunnerRestrictedTest extends TestCase
{
    use FakesToolModel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_a_restricted_runner_sends_only_the_chosen_tools(): void
    {
        Http::fake(['*' => Http::response($this->answerResponse('Hello.'))]);

        $this->restricted(['lookup_invoice'])->run([['role' => 'user', 'content' => 'Hi']], new ToolContext($this->customerUser()));

        Http::assertSent(fn (Request $request): bool => collect($request['tools'])->pluck('function.name')->all() === ['lookup_invoice']);
    }

    public function test_a_restricted_runner_with_no_tools_sends_none(): void
    {
        Http::fake(['*' => Http::response($this->answerResponse('Hello.'))]);

        $this->restricted([])->run([['role' => 'user', 'content' => 'Hi']], new ToolContext($this->customerUser()));

        Http::assertSent(fn (Request $request): bool => ! isset($request['tools']));
    }

    public function test_a_restricted_runner_refuses_a_tool_outside_its_set(): void
    {
        $user = $this->customerUser();
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->toolCall('call_1', 'open_ticket', ['summary' => 'Please open a ticket for me', 'invoice_number' => null])]))
            ->push($this->answerResponse('Sorry.'));

        $result = $this->restricted(['lookup_invoice'])->run([['role' => 'user', 'content' => 'Hi']], new ToolContext($user));

        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(2, $result->rounds);
    }

    public function test_the_original_runner_keeps_every_tool(): void
    {
        Http::fake(['*' => Http::response($this->answerResponse('Hello.'))]);
        $runner = app(ToolRunner::class);
        $runner->restrictedTo(['lookup_invoice']);

        $runner->run([['role' => 'user', 'content' => 'Hi']], new ToolContext($this->customerUser()));

        Http::assertSent(fn (Request $request): bool => count($request['tools']) === 4);
    }

    public function test_a_customer_owner_is_the_oldest_user(): void
    {
        $customer = Customer::factory()->create();
        $first = User::factory()->forCustomer($customer)->create();
        User::factory()->forCustomer($customer)->create();

        $this->assertTrue($customer->owner->is($first));
        $this->assertNull(Customer::factory()->create()->owner);
    }

    private function restricted(array $names): ToolRunner
    {
        return app(ToolRunner::class)->restrictedTo($names);
    }
}
