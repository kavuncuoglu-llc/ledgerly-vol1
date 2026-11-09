<?php

namespace App\Ai\Retrieval;

use App\Ai\AiManager;
use App\Ai\Contracts\EmbedKind;
use App\Ai\Embeddings\Embedder;
use App\Ai\Exceptions\EmbeddingFailed;
use App\Ai\Exceptions\InvalidIndexTable;
use App\Casts\Vector;
use Closure;
use Illuminate\Support\Facades\DB;

final class CandidateIndex
{
    private const COPIED_COLUMNS = 'id, source, position, heading, content, tokens, created_at, updated_at, title, heading_path, tags, help_version, source_modified_at, chunk_key, parent_id, kind, context_header';

    public function __construct(private readonly AiManager $ai) {}

    public static function assertBenchTable(string $table): void
    {
        if (preg_match('/^bench_[a-z0-9_]{1,40}$/', $table) !== 1) {
            throw InvalidIndexTable::notABenchTable($table);
        }
    }

    public function dimensionsOf(string $model): int
    {
        $vectors = $this->ai->embedUsing($model, dimensions: 0)->embed(['dimensions probe'], EmbedKind::Query);

        if (! isset($vectors[0])) {
            throw EmbeddingFailed::malformed();
        }

        return count($vectors[0]);
    }

    public function build(string $table, Embedder $embedder, int $dimensions, Closure $afterEach): int
    {
        self::assertBenchTable($table);

        $this->drop($table);

        $this->copyStructure($table, $dimensions);

        $embedded = $this->embedAll($table, $embedder, $afterEach);

        DB::statement("CREATE INDEX {$table}_embedding_hnsw ON {$table} USING hnsw ((embedding::halfvec({$dimensions})) halfvec_cosine_ops)");

        return $embedded;
    }

    public function drop(string $table): void
    {
        self::assertBenchTable($table);

        DB::statement("DROP TABLE IF EXISTS {$table}");
    }

    private function copyStructure(string $table, int $dimensions): void
    {
        DB::statement("CREATE TABLE {$table} (LIKE help_chunks INCLUDING DEFAULTS INCLUDING GENERATED)");

        DB::statement("ALTER TABLE {$table} ALTER COLUMN embedding TYPE vector({$dimensions})");

        DB::statement('INSERT INTO '.$table.' ('.self::COPIED_COLUMNS.') SELECT '.self::COPIED_COLUMNS.' FROM help_chunks');

        DB::statement("CREATE INDEX {$table}_search_idx ON {$table} USING gin (search)");
    }

    private function embedAll(string $table, Embedder $embedder, Closure $afterEach): int
    {
        $rows = DB::table('help_chunks')
            ->whereNotNull('embedding')
            ->orderBy('id')
            ->get(['id', 'content', 'context_header']);

        foreach ($rows as $row) {
            $input = trim((string) $row->context_header."\n\n".$row->content);

            DB::table($table)
                ->where('id', $row->id)
                ->update([
                    'embedding' => Vector::toLiteral($embedder->document($input)),
                ]);

            $afterEach();
        }

        return $rows->count();
    }
}
