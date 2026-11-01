<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_exports', function (Blueprint $table): void {
            $table->index(['organization_id', 'expires_at', 'id'], 'data_exports_expiry_cursor_idx');
        });
    }

    public function down(): void
    {
        Schema::table('data_exports', function (Blueprint $table): void {
            $table->dropIndex('data_exports_expiry_cursor_idx');
        });
    }
};
