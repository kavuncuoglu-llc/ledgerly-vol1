<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('replies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->index();
            $table->uuid('request_key')->nullable();
            $table->text('question');
            $table->string('status', 16)->default('pending');
            $table->text('content')->nullable();
            $table->unsignedInteger('tokens')->default(0);
            $table->json('sources')->nullable();
            $table->boolean('citations_ok')->default(true);
            $table->text('paused_for')->nullable();
            $table->uuid('resume_token')->nullable()->index();
            $table->timestamp('paused_at')->nullable();
            $table->string('error')->nullable();
            $table->string('feedback', 8)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'request_key']);
            $table->index(['status', 'paused_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replies');
    }
};
