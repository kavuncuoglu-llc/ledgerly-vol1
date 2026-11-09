<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_calls', function (Blueprint $table) {
            $table->string('prompt_version', 32)->nullable()->after('conversation_id');

            $table->index(['feature', 'prompt_version']);
        });
    }

    public function down(): void
    {
        Schema::table('model_calls', function (Blueprint $table) {
            $table->dropIndex(['feature', 'prompt_version']);

            $table->dropColumn('prompt_version');
        });
    }
};
