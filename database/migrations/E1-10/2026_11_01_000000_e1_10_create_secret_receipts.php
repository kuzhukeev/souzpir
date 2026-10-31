<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_secret_receipts', function (Blueprint $table): void {
            $table->char('nonce_hash', 64)->primary();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('consumed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_secret_receipts');
    }
};
