<?php

namespace Tests\Unit\Ai\Agents;

use App\Ai\Agents\CallSignature;
use PHPUnit\Framework\TestCase;

class CallSignatureTest extends TestCase
{
    public function test_it_reads_the_name_and_decodes_json_arguments(): void
    {
        $call = $this->call('lookup_invoice', '{"invoice_number":"4471","month":null}');

        $this->assertSame('lookup_invoice', CallSignature::name($call));
        $this->assertSame(['invoice_number' => '4471', 'month' => null], CallSignature::arguments($call));
    }

    public function test_arguments_that_are_not_an_object_come_back_empty(): void
    {
        $this->assertSame([], CallSignature::arguments($this->call('lookup_invoice', 'not json')));
        $this->assertSame([], CallSignature::arguments($this->call('lookup_invoice', '')));
    }

    public function test_arguments_already_decoded_pass_through(): void
    {
        $this->assertSame(['a' => 1], CallSignature::arguments($this->call('x', ['a' => 1])));
    }

    public function test_the_signature_ignores_argument_order_and_differs_by_tool_and_value(): void
    {
        $first = CallSignature::of($this->call('lookup_invoice', '{"a":1,"b":2}'));
        $reordered = CallSignature::of($this->call('lookup_invoice', '{"b":2,"a":1}'));
        $otherValue = CallSignature::of($this->call('lookup_invoice', '{"a":1,"b":3}'));
        $otherTool = CallSignature::of($this->call('list_invoices', '{"a":1,"b":2}'));

        $this->assertSame($first, $reordered);
        $this->assertNotSame($first, $otherValue);
        $this->assertNotSame($first, $otherTool);
    }

    public function test_describe_prints_the_call_the_way_a_trace_shows_it(): void
    {
        $call = $this->call('list_invoices', '{"status":null,"months":3}');

        $this->assertSame('list_invoices(status: null, months: 3)', CallSignature::describe($call));
        $this->assertSame('account_status()', CallSignature::describe($this->call('account_status', '{}')));
    }

    private function call(string $name, string|array $arguments): array
    {
        return [
            'id' => 'call_1',
            'type' => 'function',
            'function' => [
                'name' => $name,
                'arguments' => $arguments,
            ],
        ];
    }
}
