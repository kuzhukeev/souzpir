<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_notification_event_marks', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->ulid('brand_id');
            $t->ulid('conversation_id');
            $t->string('event_key', 60);
            $t->timestampTz('notified_at');
            $t->timestampsTz();
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $t->foreign(['conversation_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('conversations')->restrictOnDelete();
            $t->unique(['conversation_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_notification_event_marks');
    }
};
