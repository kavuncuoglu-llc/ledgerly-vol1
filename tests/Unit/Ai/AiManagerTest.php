<?php

namespace Tests\Unit\Ai;

use App\Ai\AiManager;
use App\Ai\Contracts\Capabilities\SupportsContextWindowOverride;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\EmbeddingModel;
use App\Ai\Contracts\Reranker;
use App\Ai\Decorators\ProfileMergingModel;
use App\Ai\Decorators\RetryingModel;
use App\Ai\Decorators\UsageLoggingModel;
use App\Ai\Drivers\AnthropicDriver;
use App\Ai\Drivers\FakeDriver;
use App\Ai\Drivers\GeminiDriver;
use App\Ai\Drivers\OpenAiCompatibleDriver;
use App\Ai\Exceptions\UnknownProvider;
use App\Ai\ModelCapabilities;
use App\Ai\Retrieval\LexicalReranker;
use App\Ai\Retrieval\SidecarReranker;
use Tests\TestCase;

class AiManagerTest extends TestCase
{
    public function test_chat_resolves_the_default_provider_and_decorates_it_once(): void
    {
        $model = app(AiManager::class)->chat();

        $this->assertInstanceOf(UsageLoggingModel::class, $model);
        $this->assertInstanceOf(RetryingModel::class, $model->inner());
        $this->assertInstanceOf(ProfileMergingModel::class, $model->inner()->inner());
        $this->assertInstanceOf(OpenAiCompatibleDriver::class, $model->inner()->inner()->inner());
        $this->assertSame('local', $model->name());
    }

    public function test_chat_returns_the_same_instance_for_the_same_name(): void
    {
        $ai = app(AiManager::class);

        $this->assertSame($ai->chat('local'), $ai->chat('local'));
        $this->assertNotSame($ai->chat('local'), $ai->chat('vision'));
    }

    public function test_the_container_binds_the_interfaces_to_the_default_providers(): void
    {
        $this->assertInstanceOf(ChatModel::class, app(ChatModel::class));
        $this->assertSame('local', app(ChatModel::class)->name());
        $this->assertInstanceOf(EmbeddingModel::class, app(EmbeddingModel::class));
        $this->assertSame('local-embed', app(EmbeddingModel::class)->name());
        $this->assertInstanceOf(Reranker::class, app(Reranker::class));
    }

    public function test_for_routes_a_feature_to_the_provider_the_features_map_names(): void
    {
        $ai = app(AiManager::class);

        $this->assertSame('vision', $ai->for('invoice.extract')->name());
        $this->assertSame('local', $ai->for('ticket.classify')->name());
    }

    public function test_for_falls_back_to_the_default_provider_for_an_unmapped_feature(): void
    {
        config()->set('ai.default', 'vision');

        $this->assertSame('vision', app(AiManager::class)->for('something.new')->name());
    }

    public function test_changing_where_a_feature_runs_is_one_config_line(): void
    {
        config()->set('ai.features', [...config('ai.features'), 'ticket.classify' => ['provider' => 'frontier']]);

        $this->assertSame('frontier', app(AiManager::class)->for('ticket.classify')->name());
    }

    public function test_an_unknown_provider_is_named_in_the_error(): void
    {
        $this->expectException(UnknownProvider::class);
        $this->expectExceptionMessage('No provider named [nope]');

        app(AiManager::class)->chat('nope');
    }

    public function test_an_unknown_driver_is_named_in_the_error(): void
    {
        config()->set('ai.providers.odd', ['driver' => 'telepathy']);

        $this->expectException(UnknownProvider::class);
        $this->expectExceptionMessage('telepathy');

        app(AiManager::class)->chat('odd');
    }

    public function test_each_driver_name_resolves_to_its_driver_class(): void
    {
        config()->set('ai.providers.a', ['driver' => 'anthropic', 'api_key' => 'k', 'model' => 'm']);
        config()->set('ai.providers.g', ['driver' => 'gemini', 'api_key' => 'k', 'model' => 'm']);

        $ai = app(AiManager::class);

        $this->assertInstanceOf(AnthropicDriver::class, $ai->chat('a')->inner()->inner()->inner());
        $this->assertInstanceOf(GeminiDriver::class, $ai->chat('g')->inner()->inner()->inner());
        $this->assertInstanceOf(FakeDriver::class, $ai->chat('fake')->inner()->inner()->inner());
    }

    public function test_extend_registers_a_custom_driver_like_laravels_managers(): void
    {
        config()->set('ai.providers.custom', ['driver' => 'llama-server']);
        $ai = app(AiManager::class);

        $ai->extend('llama-server', fn (array $config): ChatModel => new FakeDriver($config));

        $this->assertSame('custom', $ai->chat('custom')->name());
    }

    public function test_provider_config_fills_timeouts_and_vendor_base_urls(): void
    {
        config()->set('ai.providers.a', ['driver' => 'anthropic', 'api_key' => 'k', 'model' => 'm']);

        $config = app(AiManager::class)->providerConfig('a');

        $this->assertSame('https://api.anthropic.com', $config['base_url']);
        $this->assertSame(config('ai.timeout'), $config['timeout']);
        $this->assertSame('a', $config['name']);
    }

    public function test_a_provider_is_configured_only_when_it_has_a_key_and_a_model(): void
    {
        $ai = app(AiManager::class);

        $this->assertTrue($ai->isConfigured('local'));
        $this->assertTrue($ai->isConfigured('fake'));
        $this->assertFalse($ai->isConfigured('frontier'));
    }

    public function test_escalation_exists_only_when_the_target_is_configured(): void
    {
        $ai = app(AiManager::class);

        $this->assertFalse($ai->hasEscalation('support.reply'));
        $this->assertFalse($ai->hasEscalation('ticket.classify'));

        config()->set('ai.providers.frontier.api_key', 'sk');
        config()->set('ai.providers.frontier.model', 'claude-test');

        $this->assertTrue($ai->hasEscalation('support.reply'));
        $this->assertSame('frontier', $ai->escalation('support.reply')->name());
    }

    public function test_embed_resolves_the_embedding_provider_with_its_dimensions(): void
    {
        $model = app(AiManager::class)->embed();

        $this->assertSame('local-embed', $model->name());
        $this->assertSame('nomic-embed-text', $model->model());
        $this->assertSame(768, $model->dimensions());
    }

    public function test_embed_rejects_a_provider_that_is_not_an_embedding_driver(): void
    {
        $this->expectException(UnknownProvider::class);

        app(AiManager::class)->embed('rerank');
    }

    public function test_embed_using_overrides_the_model_and_dimensions(): void
    {
        $model = app(AiManager::class)->embedUsing('mxbai-embed-large', 1024);

        $this->assertSame('mxbai-embed-large', $model->model());
        $this->assertSame(1024, $model->dimensions());
    }

    public function test_reranker_resolves_the_sidecar_and_lexical_drivers(): void
    {
        $ai = app(AiManager::class);

        $this->assertInstanceOf(SidecarReranker::class, $ai->reranker());
        $this->assertInstanceOf(LexicalReranker::class, $ai->reranker('rerank-lexical'));
    }

    public function test_a_chat_provider_cannot_be_used_as_a_reranker(): void
    {
        $this->expectException(UnknownProvider::class);

        app(AiManager::class)->reranker('local');
    }

    public function test_the_context_window_override_reaches_through_the_decorators(): void
    {
        $model = app(AiManager::class)->chat('local');

        $this->assertTrue(ModelCapabilities::has($model, SupportsContextWindowOverride::class));
        $this->assertInstanceOf(UsageLoggingModel::class, ModelCapabilities::withContextWindow($model, 16384));
        $this->assertSame('local', ModelCapabilities::withContextWindow($model, 16384)->name());
        $this->assertNotSame($model, ModelCapabilities::withContextWindow($model, 16384));
    }

    public function test_a_driver_without_the_capability_is_returned_unchanged(): void
    {
        $model = app(AiManager::class)->chat('fake');

        $this->assertFalse(ModelCapabilities::has($model, SupportsContextWindowOverride::class));
        $this->assertSame($model->inner()->inner()->inner(), ModelCapabilities::withContextWindow($model, 16384)->inner()->inner()->inner());
    }

    public function test_requests_carry_no_provider_specific_fields(): void
    {
        $request = ChatRequest::fromOptions([['role' => 'user', 'content' => 'Hi']], ['temperature' => 0.1, 'model' => 'x', 'tools' => [['t']], 'profile' => null]);

        $this->assertSame(['temperature' => 0.1], $request->options);
        $this->assertSame('x', $request->model);
        $this->assertSame(ChatRequest::NO_PROFILE, $request->profile);
    }
}
