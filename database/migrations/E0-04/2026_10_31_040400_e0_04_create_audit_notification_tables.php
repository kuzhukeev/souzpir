<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->ulid('brand_id')->nullable();
            $t->foreignUlid('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('action', 120);
            $t->string('subject_type', 120);
            $t->string('subject_id', 120);
            $t->jsonb('before')->nullable();
            $t->jsonb('after')->nullable();
            $t->string('request_id', 128)->nullable();
            $t->string('event_id', 128)->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestampTz('created_at');
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $t->index(['organization_id', 'created_at']);
        });
        Schema::create('outbox_events', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->ulid('brand_id')->nullable();
            $t->jsonb('envelope');
            $t->timestampTz('created_at');
            $t->timestampTz('processed_at')->nullable();
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $t->unique(['id', 'organization_id']);
        });
        Schema::create('consumed_events', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('organization_id');
            $t->ulid('event_id');
            $t->string('consumer', 100);
            $t->timestampTz('created_at');
            $t->foreign(['event_id', 'organization_id'])->references(['id', 'organization_id'])->on('outbox_events')->restrictOnDelete();
            $t->unique(['consumer', 'event_id']);
        });
        Schema::create('notification_settings', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->ulid('brand_id');
            $t->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $t->string('event_key', 120);
            $t->string('channel', 20);
            $t->boolean('enabled')->default(true);
            $t->text('destination')->nullable();
            $t->timestampsTz();
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $t->unique(['brand_id', 'user_id', 'event_key', 'channel'], 'notification_settings_unique');
        });
        Schema::create('notification_deliveries', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('organization_id');
            $t->ulid('event_id');
            $t->ulid('brand_id');
            $t->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $t->string('channel', 20);
            $t->string('status', 20)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestampTz('sent_at')->nullable();
            $t->string('last_error', 120)->nullable();
            $t->timestampsTz();
            $t->foreign(['event_id', 'organization_id'])->references(['id', 'organization_id'])->on('outbox_events')->restrictOnDelete();
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $t->unique(['event_id', 'user_id', 'channel']);
        });
        Schema::create('cabinet_notifications', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->ulid('brand_id');
            $t->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $t->foreignUlid('delivery_id')->unique()->constrained('notification_deliveries')->restrictOnDelete();
            $t->string('event_key', 120);
            $t->string('title');
            $t->string('link', 300)->nullable();
            $t->timestampTz('read_at')->nullable();
            $t->timestampTz('created_at');
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $t->index(['organization_id', 'user_id', 'read_at']);
        });
        DB::statement("ALTER TABLE notification_settings ADD CONSTRAINT notification_settings_channel_check CHECK (channel IN ('cabinet','email','telegram'))");
        DB::statement("ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_channel_check CHECK (channel IN ('cabinet','email','telegram')), ADD CONSTRAINT notification_deliveries_status_check CHECK (status IN ('pending','sending','sent','failed','uncertain'))");
    }

    public function down(): void
    {
        foreach (['cabinet_notifications', 'notification_deliveries', 'notification_settings', 'consumed_events', 'outbox_events', 'audit_logs'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
