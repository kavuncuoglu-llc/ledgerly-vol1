<?php

namespace Tests\Unit\Ai\Agents;

use App\Ai\Agents\AgentState;
use PHPUnit\Framework\TestCase;

class AgentStateTest extends TestCase
{
    public function test_fresh_starts_with_a_goal_facts_and_a_unique_id(): void
    {
        $first = AgentState::fresh('Resolve the ticket', ['invoice_number' => '4471']);
        $second = AgentState::fresh('Resolve the ticket');

        $this->assertSame('Resolve the ticket', $first->goal);
        $this->assertSame(['invoice_number' => '4471'], $first->facts);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_it_survives_a_round_trip_through_an_array(): void
    {
        $state = AgentState::fresh('goal', ['a' => 1])
            ->withStep('lookup_invoice:abc', ['tool' => 'lookup_invoice', 'call' => 'lookup_invoice()', 'summary' => 'found', 'ok' => true])
            ->withFinding('invoices', [['number' => 'INV-4471']])
            ->withConclusion('It is overdue.');

        $restored = AgentState::fromArray(json_decode(json_encode($state->toArray()), true));

        $this->assertEquals($state, $restored);
        $this->assertSame($state->hash(), $restored->hash());
    }

    public function test_it_is_immutable(): void
    {
        $state = AgentState::fresh('goal');

        $changed = $state->withFinding('invoice', 'INV-4471');

        $this->assertSame([], $state->findings);
        $this->assertSame(['invoice' => 'INV-4471'], $changed->findings);
    }

    public function test_the_hash_changes_when_something_is_established_and_not_when_it_is_repeated(): void
    {
        $state = AgentState::fresh('goal');
        $step = ['tool' => 'lookup_invoice', 'call' => 'lookup_invoice()', 'summary' => 'found', 'ok' => true];

        $once = $state->withStep('lookup_invoice:abc', $step);
        $twice = $once->withStep('lookup_invoice:abc', $step);

        $this->assertNotSame($state->hash(), $once->hash());
        $this->assertSame($once->hash(), $twice->hash());
    }

    public function test_has_step_finds_a_step_by_signature(): void
    {
        $state = AgentState::fresh('goal')->withStep('a:1', ['tool' => 'a']);

        $this->assertTrue($state->hasStep('a:1'));
        $this->assertFalse($state->hasStep('a:2'));
    }

    public function test_an_appended_finding_is_not_added_twice(): void
    {
        $state = AgentState::fresh('goal')
            ->withFindingAppended('tickets', 'T-10422')
            ->withFindingAppended('tickets', 'T-10422')
            ->withFindingAppended('tickets', 'T-10431');

        $this->assertSame(['T-10422', 'T-10431'], $state->findings['tickets']);
    }

    public function test_findings_include_the_conclusion_once_there_is_one(): void
    {
        $state = AgentState::fresh('goal')->withFinding('invoice', 'INV-4471');

        $this->assertArrayNotHasKey('conclusion', $state->findings());

        $this->assertSame('Overdue.', $state->withConclusion('Overdue.')->findings()['conclusion']);
    }
}
