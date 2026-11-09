<?php

namespace Tests\Support;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use ArrayObject;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesChatCompletions;

trait FakesTriageModel
{
    use FakesChatCompletions;
    use FakesToolModel;

    protected function triageScript(array $votes, array $extraction = [], array $agentTurns = [], string $draft = 'Thanks for writing in. A team member will follow up.'): ArrayObject
    {
        return new ArrayObject([
            'votes' => $votes,
            'agent' => $agentTurns,
            'extraction' => $extraction,
            'draft' => $draft,
            'fail' => false,
        ]);
    }

    protected function fakeTriageModel(array $votes, array $extraction = [], array $agentTurns = [], string $draft = 'Thanks for writing in. A team member will follow up.'): ArrayObject
    {
        $script = $this->triageScript($votes, $extraction, $agentTurns, $draft);

        Http::fake(fn (Request $request) => $script['fail']
            ? Http::response('the model server is down', 400)
            : Http::response($this->triageReply($request, $script)));

        return $script;
    }

    protected function triageReply(Request $request, ArrayObject $script): array
    {
        $system = (string) $request['messages'][0]['content'];

        if (str_starts_with($system, 'You classify')) {
            return $this->completion($this->ticketJson($this->nextVote($script)));
        }

        if (str_starts_with($system, 'You extract')) {
            return $this->completion($this->extractionJson($script['extraction']));
        }

        if (str_starts_with($system, 'You investigate')) {
            return $this->nextAgentTurn($script);
        }

        return $this->completion($script['draft']);
    }

    protected function ticketForOwner(array $ticket = [], string $plan = 'pro'): Ticket
    {
        $customer = Customer::factory()->create(['plan' => $plan]);

        User::factory()->forCustomer($customer)->create();

        return Ticket::factory()->create([
            'customer_id' => $customer->id,
            'body' => 'Hi, my client says they paid $1,284.50 on March 3 but invoice 4471 still shows unpaid',
            'subject' => 'Client paid but invoice shows unpaid',
            ...$ticket,
        ]);
    }

    private function nextVote(ArrayObject $script): string
    {
        $votes = $script['votes'];

        $vote = count($votes) > 1
            ? array_shift($votes)
            : $votes[0];

        $script['votes'] = $votes;

        return $vote;
    }

    private function nextAgentTurn(ArrayObject $script): array
    {
        $turns = $script['agent'];

        if ($turns === []) {
            return $this->answerResponse('Nothing further to check.');
        }

        $turn = array_shift($turns);

        $script['agent'] = $turns;

        return $turn;
    }

    private function extractionJson(array $overrides): string
    {
        return $this->ticketJson(...[
            'category' => 'billing',
            'invoiceNumber' => '4471',
            'amountMentioned' => 1284.50,
            ...$overrides,
        ]);
    }
}
