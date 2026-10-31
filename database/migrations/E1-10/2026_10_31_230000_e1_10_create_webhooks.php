<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('organization_id');
            $t->ulid('brand_id');
            $t->string('url', 2048);
            $t->jsonb('events');
            $t->text('secret');
            $t->timestampsTz();
            $t->unique(['id', 'organization_id', 'brand_id']);
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
        });
        Schema::create('webhook_deliveries', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('organization_id');
            $t->ulid('brand_id');
            $t->ulid('webhook_id');
            $t->ulid('event_id')->nullable();
            $t->string('event', 120);
            $t->jsonb('payload');
            $t->string('status', 20)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->unsignedSmallInteger('status_code')->nullable();
            $t->timestampsTz();
            $t->foreign(['webhook_id', 'organization_id', 'brand_id'])->references(['id', 'organization_id', 'brand_id'])->on('webhooks')->restrictOnDelete();
            $t->unique(['webhook_id', 'event_id']);
            $t->index(['webhook_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhooks');
    }
};
