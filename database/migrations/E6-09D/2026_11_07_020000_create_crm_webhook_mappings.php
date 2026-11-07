<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_webhook_mappings', function (Blueprint $table): void {
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->ulid('webhook_id')->primary();
            $table->json('fields');
            $table->timestampTz('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_webhook_mappings');
    }
};
