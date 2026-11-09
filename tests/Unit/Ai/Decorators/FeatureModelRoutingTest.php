<?php

namespace Tests\Unit\Ai\Decorators;

use App\Ai\Contracts\ChatRequest;
use App\Ai\Decorators\ProfileMergingModel;
use App\Ai\Drivers\FakeDriver;
use App\Ai\Testing\FakeLedger;
use App\Ai\Testing\FakeScript;
use Tests\TestCase;

class FeatureModelRoutingTest extends TestCase
{
    private FakeLedger $ledger;

    private FakeScript $script;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = new FakeLedger;
        $this->script = new FakeScript($this->ledger);

        config()->set('ai.features', [
            'ticket.classify' => [
                'provider' => 'vllm',
                'model' => 'ledgerly-classify-v2',
                'profile' => 'deterministic',
            ],
            'support.reply' => [
                'provider' => 'vllm',
                'model' => null,
                'profile' => 'assistant',
            ],
        ]);
    }

    public function test_a_feature_with_a_model_sends_it_when_the_provider_is_the_one_the_feature_names(): void
    {
        $this->script->respondWith('billing');

        $this->routed('vllm')->chat($this->request('ticket.classify'));

        $this->assertSame('ledgerly-classify-v2', $this->ledger->sent()[0]['request']->model);
    }

    public function test_the_features_model_is_not_sent_to_a_different_provider(): void
    {
        $this->script->respondWith('billing');

        $this->routed('frontier')->chat($this->request('ticket.classify'));

        $this->assertNull($this->ledger->sent()[0]['request']->model);
    }

    public function test_a_model_named_on_the_request_wins(): void
    {
        $this->script->respondWith('billing');

        $this->routed('vllm')->chat($this->request('ticket.classify')->withModel('ledgerly-classify-v1'));

        $this->assertSame('ledgerly-classify-v1', $this->ledger->sent()[0]['request']->model);
    }

    public function test_a_feature_without_a_model_leaves_the_providers_default(): void
    {
        $this->script->respondWith('ok');

        $this->routed('vllm')->chat($this->request('support.reply'));

        $this->assertNull($this->ledger->sent()[0]['request']->model);
    }

    public function test_a_feature_that_is_not_configured_is_left_alone(): void
    {
        $this->script->respondWith('ok');

        $this->routed('vllm')->chat($this->request('unlisted.feature'));

        $this->assertNull($this->ledger->sent()[0]['request']->model);
    }

    public function test_a_stream_is_routed_the_same_way(): void
    {
        $this->script->respondWithStream(['bil', 'ling']);

        iterator_to_array($this->routed('vllm')->stream($this->request('ticket.classify')), false);

        $this->assertSame('ledgerly-classify-v2', $this->ledger->sent()[0]['request']->model);
    }

    private function routed(string $provider): ProfileMergingModel
    {
        return new ProfileMergingModel(new FakeDriver(['name' => $provider], $this->ledger));
    }

    private function request(string $feature): ChatRequest
    {
        return ChatRequest::fromOptions([['role' => 'user', 'content' => 'Hi']], [], $feature);
    }
}
