<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::create('cached_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->index();
            $table->string('category');
            $table->text('question');
            $table->text('answer');
            $table->string('help_version');
            $table->string('status')->default('auto');
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['category', 'help_version', 'status']);
        });

        DB::statement('ALTER TABLE cached_answers ADD COLUMN embedding vector(768)');

        DB::statement('CREATE INDEX cached_answers_embedding_idx ON cached_answers USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cached_answers');
    }
};
