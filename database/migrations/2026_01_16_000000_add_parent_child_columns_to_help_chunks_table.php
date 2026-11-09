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
            $table->foreignId('parent_id')->nullable()->index()->constrained('help_chunks')->cascadeOnDelete();
            $table->string('kind', 8)->default('parent');
            $table->text('context_header')->nullable();
        });

        $this->recreateSearchColumn("coalesce(context_header, '') || ' ' || coalesce(heading_path, '') || ' ' || coalesce(content, '')");
    }

    public function down(): void
    {
        $this->recreateSearchColumn("coalesce(heading_path, '') || ' ' || coalesce(content, '')");

        DB::statement("DELETE FROM help_chunks WHERE kind = 'child'");

        Schema::table('help_chunks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn([
                'kind',
                'context_header',
            ]);
        });
    }

    private function recreateSearchColumn(string $expression): void
    {
        DB::statement('DROP INDEX IF EXISTS help_chunks_search_idx');

        DB::statement('ALTER TABLE help_chunks DROP COLUMN search');

        DB::statement("ALTER TABLE help_chunks ADD COLUMN search tsvector GENERATED ALWAYS AS (to_tsvector('english', {$expression})) STORED");

        DB::statement('CREATE INDEX help_chunks_search_idx ON help_chunks USING gin (search)');
    }
};
