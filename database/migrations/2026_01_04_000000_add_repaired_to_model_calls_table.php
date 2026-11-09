<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_calls', function (Blueprint $table) {
            $table->boolean('repaired')->default(false)->after('prompt_version');

            $table->index(['feature', 'repaired']);
        });
    }

    public function down(): void
    {
        Schema::table('model_calls', function (Blueprint $table) {
            $table->dropIndex(['feature', 'repaired']);

            $table->dropColumn('repaired');
        });
    }
};
