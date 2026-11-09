<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('help_chunks', function (Blueprint $table) {
            $table->string('title')->default('');
            $table->string('heading_path')->default('');
            $table->jsonb('tags')->nullable();
            $table->string('help_version', 40)->default('')->index();
            $table->timestamp('source_modified_at')->useCurrent();
            $table->string('chunk_key', 64)->nullable();
        });

        DB::statement("UPDATE help_chunks SET chunk_key = md5(source || '|' || position::text)");

        DB::statement('ALTER TABLE help_chunks ALTER COLUMN chunk_key SET NOT NULL');

        DB::statement('ALTER TABLE help_chunks ADD CONSTRAINT help_chunks_chunk_key_unique UNIQUE (chunk_key)');

        DB::statement("ALTER TABLE help_chunks ADD COLUMN search tsvector GENERATED ALWAYS AS (to_tsvector('english', coalesce(heading_path, '') || ' ' || coalesce(content, ''))) STORED");

        DB::statement('CREATE INDEX help_chunks_search_idx ON help_chunks USING gin (search)');

        DB::statement('DROP INDEX IF EXISTS help_chunks_embedding_idx');

        DB::statement('CREATE INDEX help_chunks_embedding_hnsw ON help_chunks USING hnsw ((embedding::halfvec(768)) halfvec_cosine_ops) WITH (m = 16, ef_construction = 96)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS help_chunks_embedding_hnsw');

        DB::statement('CREATE INDEX help_chunks_embedding_idx ON help_chunks USING hnsw (embedding vector_cosine_ops)');

        DB::statement('DROP INDEX IF EXISTS help_chunks_search_idx');

        DB::statement('ALTER TABLE help_chunks DROP COLUMN search');

        Schema::table('help_chunks', function (Blueprint $table) {
            $table->dropColumn([
                'title',
                'heading_path',
                'tags',
                'help_version',
                'source_modified_at',
                'chunk_key',
            ]);
        });
    }
};
