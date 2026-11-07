<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_notification_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->ulid('event_id');
            $table->string('event_key');
            $table->string('channel');
            $table->text('destination');
            $table->string('destination_hash', 64);
            $table->string('status')->default('pending');
            $table->timestampsTz();
            $table->unique(['event_id', 'channel', 'destination_hash']);
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $table->foreign('event_id')->references('id')->on('outbox_events')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_notification_deliveries');
    }
};
