<?php

namespace App\Ai\Retrieval;

use App\Ai\Embeddings\Embedder;
use App\Casts\Vector;
use Illuminate\Support\Facades\DB;

final class PgRetriever implements ConfigurableRetriever
{
    public function __construct(
        private readonly Embedder $embedder,
        private RetrievalSettings $settings,
    ) {}

    public function using(RetrievalSettings $settings): static
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
            ->groupBy('id')
            ->map(fn ($group): RetrievedChunk => $this->chunkFrom($group->first(), (float) $group->max('sim'), $group->contains(fn (object $row): bool => $row->kw_rank !== null)))
            ->take($k)
            ->values()
            ->all();
    }

    private function ingestedVersion(): string
    {
        return (string) DB::table($this->settings->table)
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

    private function chunkFrom(object $row, float $similarity, bool $keywordMatched): RetrievedChunk
    {
        return new RetrievedChunk(
            id: (int) $row->id,
            source: $row->source,
            title: $row->title,
            headingPath: $row->heading_path,
            content: $row->content,
            helpVersion: $row->help_version,
            similarity: $similarity,
            rrf: (float) $row->rrf,
            keywordMatched: $keywordMatched,
            matchedText: (string) $row->matched_text,
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

        $table = $this->settings->table;

        return <<<SQL
WITH vec AS (
    {$this->vectorSide($filters, $pool)}
),
kw AS (
    {$this->keywordSide($filters, $pool)}
)
SELECT COALESCE(p.id, c.id) AS id, c.source, c.title, c.heading_path, COALESCE(p.content, c.content) AS content, c.help_version,
    CASE WHEN p.id IS NULL THEN NULL ELSE trim(COALESCE(c.context_header, '') || E'\n\n' || c.content) END AS matched_text,
    {$this->similaritySelect()} AS sim,
    kw.r AS kw_rank,
    COALESCE(1.0 / ({$rrf} + vec.r), 0) + COALESCE(1.0 / ({$rrf} + kw.r), 0) AS rrf
FROM {$table} c
LEFT JOIN {$table} p ON p.id = c.parent_id
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

        return '1 - (c.'.$this->embedding().' <=> :v::halfvec('.$this->settings->dimensions.'))';
    }

    private function vectorSide(string $filters, int $pool): string
    {
        if (! $this->usesVectorSide()) {
            return 'SELECT NULL::bigint AS id, NULL::bigint AS r WHERE false';
        }

        $embedding = $this->embedding();

        $dimensions = $this->settings->dimensions;

        $table = $this->settings->table;

        return <<<SQL
SELECT id, ROW_NUMBER() OVER (ORDER BY {$embedding} <=> :v::halfvec({$dimensions})) AS r
    FROM {$table}
    WHERE {$filters}
    ORDER BY {$embedding} <=> :v::halfvec({$dimensions})
    LIMIT {$pool}
SQL;
    }

    private function keywordSide(string $filters, int $pool): string
    {
        if ($this->settings->mode === RetrievalMode::Vector) {
            return 'SELECT NULL::bigint AS id, NULL::bigint AS r WHERE false';
        }

        $table = $this->settings->table;

        return <<<SQL
SELECT id, ROW_NUMBER() OVER (ORDER BY ts_rank(search, websearch_to_tsquery('english', :q)) DESC, id) AS r
    FROM {$table}
    WHERE {$filters} AND search @@ websearch_to_tsquery('english', :q)
    ORDER BY ts_rank(search, websearch_to_tsquery('english', :q)) DESC, id
    LIMIT {$pool}
SQL;
    }

    private function filters(RetrievalScope $scope): string
    {
        $filters = [
            'help_version = :ver',
            'embedding IS NOT NULL',
        ];

        if ($scope->plan !== '') {
            $filters[] = "(tags->'plans' IS NULL OR jsonb_exists(tags->'plans', :plan))";
        }

        if ($scope->area !== '') {
            $filters[] = "tags->>'area' = :area";
        }

        return implode(' AND ', $filters);
    }

    private function embedding(): string
    {
        return 'embedding::halfvec('.$this->settings->dimensions.')';
    }
}
