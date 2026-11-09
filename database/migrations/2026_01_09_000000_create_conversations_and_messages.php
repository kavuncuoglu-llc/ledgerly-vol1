<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('title')->nullable();
            $table->string('status')->default('open');
            $table->string('prompt_version');
            $table->text('summary')->nullable();
            $table->unsignedInteger('summary_through_seq')->default(0);
            $table->timestamps();

            $table->index(['status', 'updated_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('seq');
            $table->string('role', 16);
            $table->text('content')->nullable();
            $table->json('tool_calls')->nullable();
            $table->string('tool_call_id')->nullable();
            $table->unsignedInteger('tokens');
            $table->foreignId('model_call_id')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
