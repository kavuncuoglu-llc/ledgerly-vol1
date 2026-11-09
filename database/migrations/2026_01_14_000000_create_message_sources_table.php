<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('help_chunk_id')->nullable()->index();
            $table->string('label');
            $table->string('source');
            $table->string('heading_path');
            $table->string('help_version', 40);
            $table->timestamps();

            $table->unique([
                'message_id',
                'label',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_sources');
    }
};
