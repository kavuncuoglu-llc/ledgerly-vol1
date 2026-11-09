<?php

namespace App\Ai\Testing;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatModel;
use App\Ai\Contracts\EmbeddingModel;
use App\Ai\Contracts\Reranker;
use App\Ai\Decorators\ProfileMergingModel;
use App\Ai\Drivers\FakeDriver;
use App\Ai\Drivers\FakeEmbeddings;
use App\Ai\Retrieval\LexicalReranker;
use Illuminate\Contracts\Container\Container;

final class AiFake extends AiManager
{
    public function __construct(
        Container $app,
        private readonly FakeLedger $ledger,
    ) {
        parent::__construct($app);
    }

    public function ledger(): FakeLedger
    {
        return $this->ledger;
    }

    public function chat(?string $name = null): ChatModel
    {
        $name ??= (string) config('ai.default');

        return new ProfileMergingModel(new FakeDriver($this->fakeConfig($name), $this->ledger));
    }

    public function embed(?string $name = null): EmbeddingModel
    {
        $name ??= (string) config('ai.embedding.provider');

        return new FakeEmbeddings([
            ...$this->fakeConfig($name),
            'model' => $this->fakeConfig($name)['model'] ?? 'fake-embed',
        ]);
    }

    public function reranker(?string $name = null): Reranker
    {
        return new LexicalReranker;
    }

    public function isConfigured(string $name): bool
    {
        return in_array($name, $this->providerNames(), true);
    }

    private function fakeConfig(string $name): array
    {
        $configured = config("ai.providers.{$name}");

        return [
            ...(is_array($configured) ? $configured : []),
            'name' => $name,
        ];
    }
}
