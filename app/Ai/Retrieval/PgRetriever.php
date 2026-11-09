<?php

namespace App\Ai\Retrieval;

use App\Ai\Embeddings\Embedder;
use App\Casts\Vector;
use App\Models\HelpChunk;
use Illuminate\Support\Facades\DB;

final class PgRetriever implements Retriever
{
    private const EMBEDDING = 'embedding::halfvec(768)';

    public function __construct(
        private readonly Embedder $embedder,
        private RetrievalSettings $settings,
    ) {}

    public function using(RetrievalSettings $settings): self
    {
        $clone = clone $this;

        $clone->settings = $settings;

        return $clone;
    }

    public function settings(): RetrievalSettings
    {
        return $this->settings;
    }

    public function retrieve(string $question, RetrievalScope $scope, int $k = 5): array
    {
        $version = $this->ingestedVersion();

        if ($version === '') {
            return [];
        }

        $rows = DB::select($this->hybridSql($scope), $this->bindings($question, $scope, $version));

        return collect($rows)
            ->filter(fn (object $row): bool => $this->passesFloor($row))
            ->take($k)
            ->map(fn (object $row): RetrievedChunk => $this->chunkFrom($row))
            ->values()
            ->all();
    }

    private function ingestedVersion(): string
    {
        return (string) HelpChunk::query()
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->value('help_version');
    }

    private function passesFloor(object $row): bool
    {
        if ($row->kw_rank !== null) {
            return true;
        }

        return (float) $row->sim >= $this->settings->similarityFloor;
    }

    private function chunkFrom(object $row): RetrievedChunk
    {
        return new RetrievedChunk(
            id: (int) $row->id,
            source: $row->source,
            title: $row->title,
            headingPath: $row->heading_path,
            content: $row->content,
            helpVersion: $row->help_version,
            similarity: (float) $row->sim,
            rrf: (float) $row->rrf,
            keywordMatched: $row->kw_rank !== null,
        );
    }

    private function bindings(string $question, RetrievalScope $scope, string $version): array
    {
        $bindings = [
            'ver' => $version,
        ];

        if ($this->settings->mode !== RetrievalMode::Vector) {
            $bindings['q'] = $question;
        }

        if ($this->usesVectorSide()) {
            $bindings['v'] = Vector::toLiteral($this->embedder->query($question));
        }

        if ($scope->plan !== '') {
            $bindings['plan'] = $scope->plan;
        }

        if ($scope->area !== '') {
            $bindings['area'] = $scope->area;
        }

        return $bindings;
    }

    private function usesVectorSide(): bool
    {
        return $this->settings->mode !== RetrievalMode::Keyword;
    }

    private function hybridSql(RetrievalScope $scope): string
    {
        $filters = $this->filters($scope);

        $pool = $this->settings->candidatePool;

        $rrf = $this->settings->rrfConstant;

        return <<<SQL
WITH vec AS (
    {$this->vectorSide($filters, $pool)}
),
kw AS (
    {$this->keywordSide($filters, $pool)}
)
SELECT c.id, c.source, c.title, c.heading_path, c.content, c.help_version,
    {$this->similaritySelect()} AS sim,
    kw.r AS kw_rank,
    COALESCE(1.0 / ({$rrf} + vec.r), 0) + COALESCE(1.0 / ({$rrf} + kw.r), 0) AS rrf
FROM help_chunks c
LEFT JOIN vec ON vec.id = c.id
LEFT JOIN kw ON kw.id = c.id
WHERE vec.id IS NOT NULL OR kw.id IS NOT NULL
ORDER BY rrf DESC, c.id
LIMIT {$pool}
SQL;
    }

    private function similaritySelect(): string
    {
        if (! $this->usesVectorSide()) {
            return '0.0';
        }

        return '1 - (c.'.self::EMBEDDING.' <=> :v::halfvec(768))';
    }

    private function vectorSide(string $filters, int $pool): string
    {
        if (! $this->usesVectorSide()) {
            return 'SELECT NULL::bigint AS id, NULL::bigint AS r WHERE false';
        }

        $embedding = self::EMBEDDING;

        return <<<SQL
SELECT id, ROW_NUMBER() OVER (ORDER BY {$embedding} <=> :v::halfvec(768)) AS r
    FROM help_chunks
    WHERE {$filters}
    ORDER BY {$embedding} <=> :v::halfvec(768)
    LIMIT {$pool}
SQL;
    }

    private function keywordSide(string $filters, int $pool): string
    {
        if ($this->settings->mode === RetrievalMode::Vector) {
            return 'SELECT NULL::bigint AS id, NULL::bigint AS r WHERE false';
        }

        return <<<SQL
SELECT id, ROW_NUMBER() OVER (ORDER BY ts_rank(search, websearch_to_tsquery('english', :q)) DESC, id) AS r
    FROM help_chunks
    WHERE {$filters} AND search @@ websearch_to_tsquery('english', :q)
    ORDER BY ts_rank(search, websearch_to_tsquery('english', :q)) DESC, id
    LIMIT {$pool}
SQL;
    }

    private function filters(RetrievalScope $scope): string
    {
        $filters = [
            'help_version = :ver',
        ];

        if ($scope->plan !== '') {
            $filters[] = "(tags->'plans' IS NULL OR jsonb_exists(tags->'plans', :plan))";
        }

        if ($scope->area !== '') {
            $filters[] = "tags->>'area' = :area";
        }

        return implode(' AND ', $filters);
    }
}
