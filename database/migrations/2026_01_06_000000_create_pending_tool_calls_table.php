<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_tool_calls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id');
            $table->uuid('run_id')->nullable()->index();
            $table->unsignedSmallInteger('round')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('conversation_id')->nullable();
            $table->string('tool');
            $table->string('tool_call_id');
            $table->json('arguments');
            $table->text('summary');
            $table->string('status')->default('pending');
            $table->json('result')->nullable();
            $table->string('source')->default('web');
            $table->string('token_name')->nullable();
            $table->json('messages')->nullable();
            $table->json('options')->nullable();
            $table->string('feature')->nullable();
            $table->string('prompt_version')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_tool_calls');
    }
};
