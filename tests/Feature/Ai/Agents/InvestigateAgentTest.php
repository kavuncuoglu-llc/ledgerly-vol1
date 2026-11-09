<?php

namespace Tests\Feature\Ai\Agents;

use App\Ai\Agents\AgentState;
use App\Ai\Agents\CallSignature;
use App\Ai\Agents\InvestigateAgent;
use App\Ai\Prompts\InvestigatePrompt;
use App\Ai\Tools\ToolRegistry;
use App\Ai\Tools\ToolResult;
use App\Enums\TicketCategory;
use Tests\TestCase;

class InvestigateAgentTest extends TestCase
{
    public function test_it_is_named_and_versioned_for_the_usage_log(): void
    {
        $agent = $this->agent(TicketCategory::Billing, ['lookup_invoice']);

        $this->assertSame('investigate', $agent->name());
        $this->assertSame('agent.investigate', $agent->feature());
        $this->assertSame(InvestigatePrompt::VERSION, $agent->promptVersion());
    }

    public function test_its_budget_comes_from_the_config_it_was_given(): void
    {
        $usage = $this->agent(TicketCategory::Billing, [])->budget()->usage();

        $this->assertSame(8, $usage['max_rounds']);
        $this->assertSame(2, $usage['max_writes']);
    }

    public function test_the_prompt_is_the_system_rules_and_the_rendered_state(): void
    {
        $agent = $this->agent(TicketCategory::Billing, ['lookup_invoice']);
        $state = AgentState::fresh('Gather the facts needed to resolve: unpaid invoice', ['invoice_number' => '4471', 'amount_mentioned' => 1284.5]);

        $messages = $agent->prompt($state);

        $this->assertSame(InvestigatePrompt::system(), $messages[0]['content']);
        $this->assertStringContainsString('Goal: Gather the facts needed to resolve: unpaid invoice', $messages[1]['content']);
        $this->assertStringContainsString('Ticket category: billing', $messages[1]['content']);
        $this->assertStringContainsString("- invoice_number: 4471\n- amount_mentioned: 1284.5", $messages[1]['content']);
        $this->assertStringContainsString('- look up invoice 4471', $messages[1]['content']);
    }

    public function test_an_account_ticket_is_told_to_check_the_account_status_until_it_has(): void
    {
        $agent = $this->agent(TicketCategory::Account, ['account_status', 'open_ticket']);
        $state = AgentState::fresh('goal');

        $before = $agent->prompt($state)[1]['content'];
        $after = $agent->prompt($agent->observe($state, $this->modelCall('account_status', []), ToolResult::ok(['plan' => 'pro'])))[1]['content'];

        $this->assertStringContainsString('- check the account status', $before);
        $this->assertStringNotContainsString('check the account status', $after);
    }

    public function test_it_does_not_ask_for_a_lookup_with_no_such_tool(): void
    {
        $agent = $this->agent(TicketCategory::Billing, ['open_ticket']);

        $prompt = $agent->prompt(AgentState::fresh('goal', ['invoice_number' => '4471']))[1]['content'];

        $this->assertStringContainsString('Still to check: nothing specific', $prompt);
    }

    public function test_observing_a_found_invoice_records_a_step_and_a_finding(): void
    {
        $agent = $this->agent(TicketCategory::Billing, ['lookup_invoice']);
        $invoice = ['number' => 'INV-4471', 'status' => 'overdue', 'days_overdue' => 18, 'total' => 1284.5, 'currency' => 'USD', 'due_date' => '2026-06-01'];

        $state = $agent->observe(AgentState::fresh('goal'), $this->modelCall('lookup_invoice', ['invoice_number' => '4471', 'month' => null]), ToolResult::ok(['found' => true, 'invoice' => $invoice]));

        $this->assertSame([$invoice], $state->findings['invoices']);
        $step = array_values($state->steps)[0];
        $this->assertSame('lookup_invoice', $step['tool']);
        $this->assertSame('lookup_invoice(invoice_number: "4471", month: null)', $step['call']);
        $this->assertTrue($step['ok']);
    }

    public function test_observing_a_missing_invoice_records_that_it_was_not_found(): void
    {
        $agent = $this->agent(TicketCategory::Billing, ['lookup_invoice']);

        $state = $agent->observe(AgentState::fresh('goal'), $this->modelCall('lookup_invoice', ['invoice_number' => '9999', 'month' => null]), ToolResult::ok(['found' => false]));

        $this->assertSame(['9999'], $state->findings['invoices_not_found']);
        $this->assertArrayNotHasKey('invoices', $state->findings);
    }

    public function test_observing_a_list_stores_its_summary_and_an_opened_ticket_its_number(): void
    {
        $agent = $this->agent(TicketCategory::Billing, ['list_invoices', 'open_ticket']);
        $list = $agent->observe(AgentState::fresh('goal'), $this->modelCall('list_invoices', ['status' => null, 'month' => null, 'months' => 3]), ToolResult::text("invoices[2]{number,status}:\n  INV-4471,overdue\n  INV-4438,paid"));

        $state = $agent->observe($list, $this->modelCall('open_ticket', ['summary' => 'Follow up with the client', 'invoice_number' => null]), ToolResult::ok(['ticket_number' => 'T-10422', 'created' => true]));

        $this->assertSame('2 invoices listed; 1 overdue: INV-4471', $state->findings['recent_invoices']);
        $this->assertSame(['T-10422'], $state->findings['tickets']);
    }

    public function test_a_failed_call_is_recorded_as_a_step_but_adds_no_finding(): void
    {
        $agent = $this->agent(TicketCategory::Billing, ['lookup_invoice']);

        $state = $agent->observe(AgentState::fresh('goal'), $this->modelCall('lookup_invoice', ['invoice_number' => 'abc', 'month' => null]), ToolResult::error('invalid arguments', ['invoice_number' => ['The invoice number field format is invalid.']]));

        $this->assertSame([], $state->findings);
        $step = array_values($state->steps)[0];
        $this->assertFalse($step['ok']);
        $this->assertStringContainsString('invalid arguments (invoice_number: The invoice number field format is invalid.)', $step['summary']);
    }

    public function test_repeating_a_call_leaves_the_state_unchanged_so_the_progress_check_can_see_it(): void
    {
        $agent = $this->agent(TicketCategory::Billing, ['lookup_invoice']);
        $call = $this->modelCall('lookup_invoice', ['invoice_number' => '4471', 'month' => null]);
        $result = ToolResult::ok(['found' => false]);

        $once = $agent->observe(AgentState::fresh('goal'), $call, $result);
        $twice = $agent->observe($once, $call, $result);

        $this->assertSame(CallSignature::of($call), array_key_first($once->steps));
        $this->assertSame($once->hash(), $twice->hash());
    }

    public function test_the_same_call_with_its_arguments_in_another_order_is_still_a_repeat(): void
    {
        $agent = $this->agent(TicketCategory::Billing, ['lookup_invoice']);
        $result = ToolResult::ok(['found' => false]);
        $first = $this->modelCall('lookup_invoice', ['month' => null, 'invoice_number' => '4471']);
        $reordered = $this->modelCall('lookup_invoice', ['invoice_number' => '4471', 'month' => null]);

        $once = $agent->observe(AgentState::fresh('goal'), $first, $result);
        $twice = $agent->observe($once, $reordered, $result);

        $this->assertSame($once->hash(), $twice->hash());
    }

    public function test_the_same_call_with_a_different_result_updates_the_step(): void
    {
        $agent = $this->agent(TicketCategory::Billing, ['lookup_invoice']);
        $call = $this->modelCall('lookup_invoice', ['invoice_number' => '4471', 'month' => null]);

        $failed = $agent->observe(AgentState::fresh('goal'), $call, ToolResult::error('tool failed'));
        $recovered = $agent->observe($failed, $call, ToolResult::ok(['found' => false]));

        $this->assertNotSame($failed->hash(), $recovered->hash());
        $this->assertTrue(array_values($recovered->steps)[0]['ok']);
    }

    public function test_once_something_is_established_and_nothing_is_pending_the_prompt_says_to_reply(): void
    {
        $agent = $this->agent(TicketCategory::Billing, ['lookup_invoice']);
        $fresh = AgentState::fresh('goal');
        $looked = $agent->observe($fresh, $this->modelCall('lookup_invoice', ['invoice_number' => '4471', 'month' => null]), ToolResult::ok(['found' => false]));

        $this->assertStringNotContainsString('Nothing is left to check', $agent->prompt($fresh)[1]['content']);
        $this->assertStringContainsString('Nothing is left to check. Reply now with your findings in plain sentences and do not call a tool.', $agent->prompt($looked)[1]['content']);
    }

    public function test_it_does_not_say_to_reply_while_something_is_still_pending(): void
    {
        $agent = $this->agent(TicketCategory::Billing, ['lookup_invoice', 'list_invoices']);
        $listed = $agent->observe(AgentState::fresh('goal', ['invoice_number' => '4471']), $this->modelCall('list_invoices', ['status' => null, 'month' => null, 'months' => 3]), ToolResult::text('No invoices match.'));

        $prompt = $agent->prompt($listed)[1]['content'];

        $this->assertStringContainsString('- look up invoice 4471', $prompt);
        $this->assertStringNotContainsString('Nothing is left to check', $prompt);
    }

    public function test_it_asks_for_a_deterministic_short_reply(): void
    {
        $options = $this->agent(TicketCategory::Billing, [])->options();

        $this->assertSame('deterministic', $options['profile']);
        $this->assertSame(InvestigateAgent::MAX_TOKENS, $options['max_tokens']);
    }

    private function agent(TicketCategory $category, array $tools): InvestigateAgent
    {
        return new InvestigateAgent($category, app(ToolRegistry::class)->only($tools), config('ai.agents.triage'));
    }

    private function modelCall(string $name, array $arguments): array
    {
        return [
            'id' => 'call_1',
            'type' => 'function',
            'function' => [
                'name' => $name,
                'arguments' => json_encode($arguments),
            ],
        ];
    }
}
