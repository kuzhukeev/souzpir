<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_event_snapshots', function (Blueprint $table): void {
            $table->ulid('event_id')->primary();
            $table->json('subscriptions');
            $table->timestampsTz();
            $table->foreign('event_id')->references('id')->on('outbox_events')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_event_snapshots');
    }
};
