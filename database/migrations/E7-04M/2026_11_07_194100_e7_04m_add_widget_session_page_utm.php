<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widget_sessions', function (Blueprint $table): void {
            $table->string('page', 2048)->nullable();
            $table->string('utm', 512)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('widget_sessions', function (Blueprint $table): void {
            $table->dropColumn(['page', 'utm']);
        });
    }
};
