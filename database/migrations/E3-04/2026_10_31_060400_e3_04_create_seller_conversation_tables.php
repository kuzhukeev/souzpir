<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->ulid('brand_id');
            $t->string('name')->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('email', 254)->nullable();
            $t->jsonb('external_ids')->default('{}');
            $t->ulid('merged_into_id')->nullable();
            $t->timestampsTz();
            $t->unique(['id', 'brand_id', 'organization_id']);
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $t->foreign(['merged_into_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('contacts')->restrictOnDelete();
        });
        Schema::create('channel_accounts', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->ulid('brand_id');
            $t->string('type', 32);
            $t->string('name')->nullable();
            $t->text('credentials')->nullable();
            $t->text('managers_chat_id')->nullable();
            $t->string('default_mode', 20)->default('bot');
            $t->string('status', 20)->default('unconfigured');
            $t->jsonb('settings')->default('{}');
            $t->timestampsTz();
            $t->unique(['id', 'brand_id', 'organization_id']);
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
        });
        Schema::create('brand_seller_settings', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->ulid('brand_id')->unique();
            $t->jsonb('followup');
            $t->jsonb('off_topic');
            $t->jsonb('negative');
            $t->jsonb('tools');
            $t->jsonb('greetings');
            $t->jsonb('phrases');
            $t->jsonb('schedule');
            $t->jsonb('assignment');
            $t->boolean('catalog_prices_enabled')->default(false);
            $t->timestampsTz();
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
        });
        Schema::create('channel_inbound_events', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('organization_id');
            $t->ulid('brand_id');
            $t->ulid('channel_account_id');
            $t->string('external_id', 255);
            $t->jsonb('payload');
            $t->timestampTz('processed_at')->nullable();
            $t->timestampsTz();
            $t->foreign(['channel_account_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('channel_accounts')->restrictOnDelete();
            $t->unique(['channel_account_id', 'external_id']);
            $t->unique(['id', 'brand_id', 'organization_id']);
        });
        Schema::create('conversations', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->ulid('brand_id');
            $t->ulid('channel_account_id')->nullable();
            $t->ulid('contact_id')->nullable();
            $t->string('stage', 32)->default('open');
            $t->string('outcome', 64)->nullable();
            $t->string('client_type', 64)->default('unknown');
            $t->jsonb('state')->default('{}');
            $t->text('summary')->nullable();
            $t->string('mode', 20)->default('bot');
            $t->foreignUlid('assigned_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->boolean('do_not_disturb')->default(false);
            $t->unsignedInteger('followups_sent')->default(0);
            $t->unsignedInteger('version')->default(0);
            $t->timestampTz('next_followup_at')->nullable();
            $t->timestampTz('last_client_message_at')->nullable();
            $t->timestampTz('last_bot_message_at')->nullable();
            $t->timestampTz('handed_off_at')->nullable();
            $t->timestampTz('taken_at')->nullable();
            $t->timestampsTz();
            $t->unique(['id', 'brand_id', 'organization_id']);
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $t->foreign(['channel_account_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('channel_accounts')->restrictOnDelete();
            $t->foreign(['contact_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('contacts')->restrictOnDelete();
            $t->foreign(['organization_id', 'assigned_user_id'])->references(['organization_id', 'user_id'])->on('memberships')->restrictOnDelete();
        });
        Schema::create('messages', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('organization_id');
            $t->ulid('brand_id');
            $t->ulid('conversation_id');
            $t->string('role', 20);
            $t->text('text');
            $t->jsonb('meta')->default('{}');
            $t->timestampTz('delivered_at')->nullable();
            $t->string('delivery_error', 120)->nullable();
            $t->timestampsTz();
            $t->foreign(['conversation_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('conversations')->restrictOnDelete();
            $t->unique(['id', 'conversation_id', 'brand_id', 'organization_id']);
            $t->unique(['id', 'brand_id', 'organization_id']);
            $t->index(['conversation_id', 'created_at']);
        });
        Schema::create('seller_turns', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('organization_id');
            $t->ulid('brand_id');
            $t->ulid('conversation_id');
            $t->ulid('input_message_id');
            $t->ulid('external_event_id')->nullable();
            $t->string('idempotency_key', 128);
            $t->char('request_hash', 64);
            $t->char('claim_hash', 64);
            $t->unsignedInteger('base_version');
            $t->string('status', 20)->default('processing');
            $t->jsonb('outcome')->nullable();
            $t->char('outcome_hash', 64)->nullable();
            $t->timestampsTz();
            $t->foreign(['conversation_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('conversations')->restrictOnDelete();
            $t->foreign(['input_message_id', 'conversation_id', 'brand_id', 'organization_id'])->references(['id', 'conversation_id', 'brand_id', 'organization_id'])->on('messages')->restrictOnDelete();
            $t->foreign(['external_event_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('channel_inbound_events')->restrictOnDelete();
            $t->unique(['brand_id', 'idempotency_key']);
            $t->unique('input_message_id');
        });
        Schema::create('seller_tasks', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $t->ulid('brand_id');
            $t->ulid('conversation_id');
            $t->string('type', 40);
            $t->jsonb('payload')->default('{}');
            $t->timestampTz('due_at')->nullable();
            $t->string('status', 20)->default('new');
            $t->string('priority', 20)->default('normal');
            $t->foreignUlid('assigned_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
            $t->foreign(['conversation_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('conversations')->restrictOnDelete();
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $t->foreign(['organization_id', 'assigned_user_id'])->references(['organization_id', 'user_id'])->on('memberships')->restrictOnDelete();
        });
        Schema::create('message_reviews', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('organization_id');
            $t->ulid('brand_id');
            $t->ulid('message_id');
            $t->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $t->string('type', 32);
            $t->text('note')->nullable();
            $t->text('better_text')->nullable();
            $t->string('status', 20)->default('new');
            $t->string('applied_to', 32)->nullable();
            $t->timestampsTz();
            $t->foreign(['message_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('messages')->restrictOnDelete();
            $t->foreign(['organization_id', 'user_id'])->references(['organization_id', 'user_id'])->on('memberships')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE channel_accounts ADD CONSTRAINT e304_channel_mode_check CHECK (default_mode IN ('bot','suggest','manager')), ADD CONSTRAINT e304_channel_type_check CHECK (type IN ('telegram','widget','api','email','whatsapp')), ADD CONSTRAINT e304_channel_status_check CHECK (status IN ('unconfigured','active','disabled','error','planned'))");
        DB::statement("ALTER TABLE conversations ADD CONSTRAINT e304_conversation_mode_check CHECK (mode IN ('bot','suggest','manager','paused'))");
        DB::statement("ALTER TABLE messages ADD CONSTRAINT e304_message_role_check CHECK (role IN ('client','agent','system','tool','manager'))");
        DB::statement("ALTER TABLE seller_turns ADD CONSTRAINT e304_turn_status_check CHECK (status IN ('processing','completed','suggested','suppressed','failed')), ADD CONSTRAINT e304_turn_hash_check CHECK (request_hash ~ '^[0-9a-f]{64}$' AND claim_hash ~ '^[0-9a-f]{64}$')");
        DB::statement("CREATE UNIQUE INDEX e304_one_active_turn_per_conversation ON seller_turns(conversation_id) WHERE status = 'processing'");
        DB::statement("ALTER TABLE message_reviews ADD CONSTRAINT e304_review_type_check CHECK (type IN ('unclear','bureaucratic','too_long','wrong_fact','wrong_tone','other'))");
    }

    public function down(): void
    {
        foreach (['message_reviews', 'seller_tasks', 'seller_turns', 'messages', 'conversations', 'channel_inbound_events', 'brand_seller_settings', 'channel_accounts', 'contacts'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
