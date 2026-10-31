<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_secret_receipts', function (Blueprint $table): void {
            $table->index(['expires_at', 'nonce_hash'], 'e110_receipts_expiry_nonce_idx');
        });
    }

    public function down(): void
    {
        Schema::table('integration_secret_receipts', function (Blueprint $table): void {
            $table->dropIndex('e110_receipts_expiry_nonce_idx');
        });
    }
};
