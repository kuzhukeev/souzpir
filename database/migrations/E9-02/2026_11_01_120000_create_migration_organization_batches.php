<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_organization_batches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id')->unique();
            $table->ulid('owner_user_id');
            $table->char('source_hash', 64);
            $table->unsignedInteger('brand_count');
            $table->jsonb('snapshot');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_organization_batches');
    }
};
