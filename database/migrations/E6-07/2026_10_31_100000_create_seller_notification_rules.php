<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_notification_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->string('event_key', 120);
            $table->string('recipient', 20);
            $table->boolean('cabinet');
            $table->boolean('email');
            $table->boolean('telegram');
            $table->string('timing', 12);
            $table->timestampsTz();
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $table->unique(['brand_id', 'event_key']);
        });
        Schema::create('seller_digest_preferences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->string('digest_time', 5)->default('09:00');
            $table->timestampsTz();
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $table->unique(['organization_id', 'brand_id', 'user_id'], 'seller_digest_preference_unique');
        });
        Schema::create('seller_digest_batches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->string('channel', 20);
            $table->timestampTz('due_at');
            $table->date('slot_date');
            $table->string('status', 20)->default('pending');
            $table->string('last_error', 40)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $table->unique(['organization_id', 'brand_id', 'user_id', 'channel', 'slot_date'], 'seller_digest_batch_slot_unique');
        });
        Schema::create('seller_digest_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->ulid('event_id');
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->string('channel', 20);
            $table->timestampTz('due_at');
            $table->date('slot_date');
            $table->foreignUlid('batch_id')->nullable()->constrained('seller_digest_batches')->restrictOnDelete();
            $table->timestampsTz();
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $table->foreign(['event_id', 'organization_id'])->references(['id', 'organization_id'])->on('outbox_events')->restrictOnDelete();
            $table->unique(['event_id', 'user_id', 'channel']);
            $table->index(['due_at', 'organization_id', 'brand_id', 'user_id', 'channel']);
        });
        Schema::create('seller_digest_cabinet_notifications', function (Blueprint $table): void {
            $table->foreignUlid('batch_id')->primary()->constrained('seller_digest_batches')->restrictOnDelete();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->jsonb('titles');
            $table->jsonb('event_keys');
            $table->timestampTz('created_at');
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $table->index(['organization_id', 'brand_id', 'user_id', 'created_at'], 'seller_digest_inbox_scope');
        });
        Schema::create('seller_test_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('event_key', 120);
            $table->string('channel', 20);
            $table->string('status', 20)->default('pending');
            $table->string('last_error', 40)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $table->unique(['organization_id', 'brand_id', 'user_id', 'request_key'], 'seller_test_request_unique');
        });
        Schema::create('seller_test_cabinet_notifications', function (Blueprint $table): void {
            $table->foreignUlid('delivery_id')->primary()->constrained('seller_test_deliveries')->restrictOnDelete();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->timestampTz('created_at');
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE seller_notification_rules ADD CONSTRAINT seller_rule_recipient_check CHECK (recipient IN ('all','owner','admin','manager','editor')), ADD CONSTRAINT seller_rule_timing_check CHECK (timing IN ('immediate','digest'))");
        DB::statement("ALTER TABLE seller_digest_preferences ADD CONSTRAINT seller_digest_time_check CHECK (digest_time ~ '^([01][0-9]|2[0-3]):[0-5][0-9]$')");
        DB::statement("ALTER TABLE seller_digest_items ADD CONSTRAINT seller_digest_channel_check CHECK (channel IN ('cabinet','email','telegram'))");
        DB::statement("ALTER TABLE seller_digest_batches ADD CONSTRAINT seller_digest_batch_channel_check CHECK (channel IN ('cabinet','email','telegram')), ADD CONSTRAINT seller_digest_batch_status_check CHECK (status IN ('pending','sending','sent','failed','uncertain','cancelled'))");
        DB::statement("ALTER TABLE seller_test_deliveries ADD CONSTRAINT seller_test_channel_check CHECK (channel IN ('cabinet','email','telegram')), ADD CONSTRAINT seller_test_status_check CHECK (status IN ('pending','sending','sent','failed','uncertain','cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_test_cabinet_notifications');
        Schema::dropIfExists('seller_test_deliveries');
        Schema::dropIfExists('seller_digest_cabinet_notifications');
        Schema::dropIfExists('seller_digest_items');
        Schema::dropIfExists('seller_digest_batches');
        Schema::dropIfExists('seller_digest_preferences');
        Schema::dropIfExists('seller_notification_rules');
    }
};
