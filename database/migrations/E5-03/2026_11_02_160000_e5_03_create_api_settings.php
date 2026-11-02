<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_api_settings', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('organization_id');
            $t->ulid('brand_id');
            $t->jsonb('mapping');
            $t->timestampsTz();
            $t->unique(['organization_id', 'brand_id']);
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
        });
        Schema::create('seller_api_lead_requests', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('organization_id');
            $t->ulid('brand_id');
            $t->string('endpoint', 32);
            $t->string('idempotency_key', 128);
            $t->char('request_hash', 64);
            $t->ulid('contact_id');
            $t->ulid('conversation_id');
            $t->string('status', 20);
            $t->timestampsTz();
            $t->unique(['brand_id', 'endpoint', 'idempotency_key']);
            $t->foreign(['contact_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('contacts')->restrictOnDelete();
            $t->foreign(['conversation_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('conversations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_api_lead_requests');
        Schema::dropIfExists('seller_api_settings');
    }
};
