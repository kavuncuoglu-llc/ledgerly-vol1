<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('conversation_id')->nullable();
            $table->foreignId('model_call_id')->nullable();
            $table->string('tool');
            $table->string('tool_call_id')->nullable();
            $table->json('arguments');
            $table->string('decision');
            $table->json('result')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('source')->default('web');
            $table->string('token_name')->nullable();
            $table->timestamps();

            $table->index(['tool', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_calls');
    }
};
