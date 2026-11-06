<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_accounts', function (Blueprint $table): void {
            $table->timestampTz('connected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('channel_accounts', function (Blueprint $table): void {
            $table->dropColumn('connected_at');
        });
    }
};
