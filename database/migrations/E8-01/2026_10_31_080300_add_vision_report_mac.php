<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vision_reports', function (Blueprint $table): void {
            $table->char('snapshot_mac', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vision_reports', function (Blueprint $table): void {
            $table->dropColumn('snapshot_mac');
        });
    }
};
