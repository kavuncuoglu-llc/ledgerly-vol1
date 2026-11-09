<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->string('agent');
            $table->foreignId('user_id');
            $table->foreignId('triage_run_id')->nullable()->index();
            $table->string('status', 32);
            $table->string('reason')->nullable();
            $table->text('goal');
            $table->json('state');
            $table->json('budget');
            $table->json('trace');
            $table->unsignedSmallInteger('rounds')->default(0);
            $table->unsignedInteger('tokens')->default(0);
            $table->unsignedSmallInteger('writes')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();

            $table->index(['agent', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
