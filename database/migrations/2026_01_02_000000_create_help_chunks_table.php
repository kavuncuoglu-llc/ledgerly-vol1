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

        Schema::create('help_chunks', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->unsignedSmallInteger('position');
            $table->string('heading');
            $table->text('content');
            $table->unsignedSmallInteger('tokens');
            $table->timestamps();

            $table->unique(['source', 'position']);
        });

        DB::statement('ALTER TABLE help_chunks ADD COLUMN embedding vector(768)');

        DB::statement('CREATE INDEX help_chunks_embedding_idx ON help_chunks USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('help_chunks');
    }
};
