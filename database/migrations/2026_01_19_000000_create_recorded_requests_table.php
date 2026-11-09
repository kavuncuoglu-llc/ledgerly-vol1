<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recorded_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('model_call_id')->nullable()->index();
            $table->string('feature');
            $table->string('provider');
            $table->foreignId('conversation_id')->nullable()->index();
            $table->json('request');
            $table->timestamps();

            $table->index(['feature', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recorded_requests');
    }
};
