<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_calls', function (Blueprint $table): void {
            $table->string('provider_generation_id', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_calls', function (Blueprint $table): void {
            $table->dropColumn('provider_generation_id');
        });
    }
};
