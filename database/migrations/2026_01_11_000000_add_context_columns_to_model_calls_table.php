<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_calls', function (Blueprint $table) {
            $table->unsignedInteger('cached_tokens')->nullable();
            $table->boolean('cached_tokens_estimated')->default(false);
            $table->unsignedInteger('ttft_ms')->nullable();
            $table->json('context_breakdown')->nullable();
            $table->boolean('semantic_cache_hit')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('model_calls', function (Blueprint $table) {
            $table->dropColumn([
                'cached_tokens',
                'cached_tokens_estimated',
                'ttft_ms',
                'context_breakdown',
                'semantic_cache_hit',
            ]);
        });
    }
};
