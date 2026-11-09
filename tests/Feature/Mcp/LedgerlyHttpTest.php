<?php

namespace Tests\Feature\Mcp;

use App\Models\ToolCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\FakesToolModel;
use Tests\TestCase;

class LedgerlyHttpTest extends TestCase
{
    use FakesToolModel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('ai');
    }

    public function test_a_request_without_a_token_is_rejected_with_401(): void
    {
        $response = $this->postJson('mcp/ledgerly', $this->rpc('tools/list'));

        $response->assertUnauthorized();
    }

    public function test_a_garbage_token_is_rejected_with_401(): void
    {
        $response = $this->withToken('1|not-a-real-token')->postJson('mcp/ledgerly', $this->rpc('tools/list'));

        $response->assertUnauthorized();
    }

    public function test_a_logged_in_session_alone_does_not_open_the_endpoint(): void
    {
        $response = $this->actingAs($this->customerUser())->postJson('mcp/ledgerly', $this->rpc('tools/list'));

        $response->assertUnauthorized();
    }

    public function test_get_and_delete_are_not_allowed(): void
    {
        $this->get('mcp/ledgerly')->assertStatus(405);
        $this->delete('mcp/ledgerly')->assertStatus(405);
    }

    public function test_a_valid_token_lists_the_native_tools(): void
    {
        $token = $this->customerUser()->createToken('cursor-jeremy')->plainTextToken;

        $response = $this->withToken($token)->postJson('mcp/ledgerly', $this->rpc('tools/list'));

        $response->assertOk();
        $names = array_column($response->json('result.tools'), 'name');
        $this->assertSame(['lookup_invoice', 'list_invoices', 'open_ticket'], $names);
    }

    public function test_a_tool_call_over_http_is_scoped_and_audited_with_the_token_name(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user);
        $token = $user->createToken('cursor-jeremy')->plainTextToken;

        $response = $this->withToken($token)->postJson('mcp/ledgerly', $this->rpc('tools/call', [
            'name' => 'list_invoices',
            'arguments' => ['status' => 'overdue', 'month' => null],
        ]));

        $response->assertOk();
        $this->assertStringContainsString('INV-4471', $response->json('result.content.0.text'));
        $audit = ToolCall::query()->sole();
        $this->assertSame('mcp', $audit->source);
        $this->assertSame('cursor-jeremy', $audit->token_name);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('allowed', $audit->decision);
    }

    public function test_a_revoked_token_gets_401_instead_of_data(): void
    {
        $user = $this->customerUser();
        $token = $user->createToken('cursor-jeremy');
        $this->withToken($token->plainTextToken)->postJson('mcp/ledgerly', $this->rpc('tools/list'))->assertOk();

        $token->accessToken->delete();
        $this->app['auth']->forgetGuards();
        $response = $this->withToken($token->plainTextToken)->postJson('mcp/ledgerly', $this->rpc('tools/list'));

        $response->assertUnauthorized();
    }

    public function test_a_token_stops_working_after_the_configured_lifetime(): void
    {
        $token = $this->customerUser()->createToken('cursor-jeremy')->plainTextToken;
        $this->travel(config('sanctum.expiration') + 1)->minutes();

        $response = $this->withToken($token)->postJson('mcp/ledgerly', $this->rpc('tools/list'));

        $response->assertUnauthorized();
    }

    public function test_a_worker_token_with_an_allow_list_can_read_but_not_write(): void
    {
        config()->set('ai.tool_allow_lists', ['invoice-worker' => ['lookup_invoice', 'list_invoices']]);
        $user = $this->customerUser();
        $this->invoiceFor($user);
        $token = $user->createToken('invoice-worker')->plainTextToken;

        $read = $this->withToken($token)->postJson('mcp/ledgerly', $this->rpc('tools/call', [
            'name' => 'lookup_invoice',
            'arguments' => ['invoice_number' => '4471', 'month' => null],
        ]));
        $write = $this->withToken($token)->postJson('mcp/ledgerly', $this->rpc('tools/call', [
            'name' => 'open_ticket',
            'arguments' => ['summary' => 'Open a ticket for me please', 'invoice_number' => null],
        ]));

        $this->assertStringContainsString('INV-4471', $read->json('result.content.0.text'));
        $this->assertTrue($write->json('result.isError'));
        $this->assertStringContainsString('not permitted', $write->json('result.content.0.text'));
        $decisions = ToolCall::query()->orderBy('id')->pluck('decision', 'tool')->all();
        $this->assertSame(['lookup_invoice' => 'allowed', 'open_ticket' => 'denied'], $decisions);
    }

    public function test_the_token_of_a_user_without_a_customer_cannot_use_account_tools(): void
    {
        $token = User::factory()->create()->createToken('orphan')->plainTextToken;

        $response = $this->withToken($token)->postJson('mcp/ledgerly', $this->rpc('tools/call', [
            'name' => 'list_invoices',
            'arguments' => ['status' => null, 'month' => null],
        ]));

        $this->assertTrue($response->json('result.isError'));
        $this->assertSame('denied', ToolCall::query()->sole()->decision);
    }

    public function test_the_endpoint_is_throttled_like_the_chat_endpoint(): void
    {
        config()->set('ai.rate_limit', 2);
        $token = $this->customerUser()->createToken('cursor-jeremy')->plainTextToken;

        $this->withToken($token)->postJson('mcp/ledgerly', $this->rpc('tools/list'))->assertOk();
        $this->withToken($token)->postJson('mcp/ledgerly', $this->rpc('tools/list'))->assertOk();
        $response = $this->withToken($token)->postJson('mcp/ledgerly', $this->rpc('tools/list'));

        $response->assertTooManyRequests();
    }

    private function rpc(string $method, array $params = []): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ];
    }
}
