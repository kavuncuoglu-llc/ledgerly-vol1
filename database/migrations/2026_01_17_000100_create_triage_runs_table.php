<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('triage_runs', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->index();
            $table->string('category')->nullable();
            $table->decimal('agreement', 4, 2)->nullable();
            $table->json('votes')->nullable();
            $table->string('reason')->nullable();
            $table->json('fields')->nullable();
            $table->boolean('urgent')->default(false);
            $table->string('assignee')->nullable();
            $table->json('findings')->nullable();
            $table->text('draft')->nullable();
            $table->foreignId('agent_run_id')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('triage_runs');
    }
};
