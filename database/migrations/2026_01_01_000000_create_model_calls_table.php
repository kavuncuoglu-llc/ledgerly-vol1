<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_calls', function (Blueprint $table) {
            $table->id();
            $table->string('feature');
            $table->string('provider');
            $table->string('model');
            $table->unsignedInteger('prompt_tokens');
            $table->unsignedInteger('completion_tokens');
            $table->string('finish_reason', 32);
            $table->unsignedInteger('latency_ms');
            $table->unsignedBigInteger('cost_micros')->default(0);
            $table->foreignId('conversation_id')->nullable()->index();
            $table->timestamps();

            $table->index(['feature', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_calls');
    }
};
