<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_stage_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->ulid('conversation_id');
            $table->string('from_stage', 32);
            $table->string('to_stage', 32);
            $table->timestampTz('occurred_at');
            $table->index(['organization_id', 'brand_id', 'occurred_at']);
            $table->foreign(['conversation_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('conversations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_stage_events');
    }
};
