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
            $t->unique(['id', 'channel_account_id', 'brand_id', 'organization_id']);
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
            $t->unique(['id', 'channel_account_id', 'brand_id', 'organization_id']);
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
            $t->ulid('channel_account_id')->nullable();
            $t->ulid('external_event_id')->nullable()->unique();
            $t->string('role', 20);
            $t->text('text');
            $t->jsonb('meta')->default('{}');
            $t->timestampTz('delivered_at')->nullable();
            $t->string('delivery_error', 120)->nullable();
            $t->timestampsTz();
            $t->foreign(['conversation_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('conversations')->restrictOnDelete();
            $t->foreign(['conversation_id', 'channel_account_id', 'brand_id', 'organization_id'])->references(['id', 'channel_account_id', 'brand_id', 'organization_id'])->on('conversations')->restrictOnDelete();
            $t->foreign(['external_event_id', 'channel_account_id', 'brand_id', 'organization_id'])->references(['id', 'channel_account_id', 'brand_id', 'organization_id'])->on('channel_inbound_events')->restrictOnDelete();
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
            $t->unique('external_event_id');
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
        DB::statement("ALTER TABLE messages ADD CONSTRAINT e304_message_role_check CHECK (role IN ('client','agent','system','tool','manager')), ADD CONSTRAINT e304_message_inbound_binding CHECK ((external_event_id IS NULL AND channel_account_id IS NULL) OR (external_event_id IS NOT NULL AND channel_account_id IS NOT NULL AND role = 'client'))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION e304_immutable_message_origin() RETURNS trigger AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    IF OLD.external_event_id IS NOT NULL THEN
      RAISE EXCEPTION 'Inbound message origin is immutable' USING ERRCODE = '23514';
    END IF;
    RETURN OLD;
  END IF;
  IF TG_OP = 'UPDATE' AND OLD.conversation_id IS DISTINCT FROM NEW.conversation_id THEN
    RAISE EXCEPTION 'Message conversation is immutable' USING ERRCODE = '23514';
  END IF;
  -- Serialize inserting/changing a message with changes to its conversation channel.
  PERFORM 1 FROM conversations WHERE id = NEW.conversation_id FOR UPDATE;
  IF NEW.role = 'client' AND NEW.external_event_id IS NULL AND EXISTS (SELECT 1 FROM conversations WHERE id = NEW.conversation_id AND channel_account_id IS NOT NULL) THEN
    RAISE EXCEPTION 'Channel client message requires inbound event' USING ERRCODE = '23514';
  END IF;
  IF TG_OP = 'UPDATE' AND (OLD.external_event_id IS DISTINCT FROM NEW.external_event_id OR OLD.channel_account_id IS DISTINCT FROM NEW.channel_account_id OR
     (OLD.external_event_id IS NOT NULL AND (OLD.organization_id IS DISTINCT FROM NEW.organization_id OR OLD.brand_id IS DISTINCT FROM NEW.brand_id OR OLD.conversation_id IS DISTINCT FROM NEW.conversation_id OR OLD.role IS DISTINCT FROM NEW.role OR OLD.text IS DISTINCT FROM NEW.text))) THEN
    RAISE EXCEPTION 'Inbound message origin is immutable' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER e304_immutable_message_origin BEFORE INSERT OR UPDATE OR DELETE ON messages FOR EACH ROW EXECUTE FUNCTION e304_immutable_message_origin();
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION e304_immutable_conversation_channel() RETURNS trigger AS $$
BEGIN
  IF OLD.channel_account_id IS DISTINCT FROM NEW.channel_account_id AND EXISTS (SELECT 1 FROM messages WHERE conversation_id = OLD.id) THEN
    RAISE EXCEPTION 'Conversation channel cannot change after messages exist' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER e304_immutable_conversation_channel BEFORE UPDATE OF channel_account_id ON conversations FOR EACH ROW EXECUTE FUNCTION e304_immutable_conversation_channel();
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION e304_verify_turn_origin() RETURNS trigger AS $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM messages m WHERE m.id = NEW.input_message_id AND m.conversation_id = NEW.conversation_id AND m.organization_id = NEW.organization_id AND m.brand_id = NEW.brand_id AND m.external_event_id IS NOT DISTINCT FROM NEW.external_event_id AND m.role = 'client' AND (m.external_event_id IS NOT NULL OR NOT EXISTS (SELECT 1 FROM conversations c WHERE c.id = m.conversation_id AND c.channel_account_id IS NOT NULL))) THEN
    RAISE EXCEPTION 'Turn input does not match message origin' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER e304_verify_turn_origin BEFORE INSERT OR UPDATE OF input_message_id, external_event_id, conversation_id, brand_id, organization_id ON seller_turns FOR EACH ROW EXECUTE FUNCTION e304_verify_turn_origin();
SQL);
        DB::statement("ALTER TABLE seller_turns ADD CONSTRAINT e304_turn_status_check CHECK (status IN ('processing','completed','suggested','suppressed','failed')), ADD CONSTRAINT e304_turn_hash_check CHECK (request_hash ~ '^[0-9a-f]{64}$' AND claim_hash ~ '^[0-9a-f]{64}$')");
        DB::statement("CREATE UNIQUE INDEX e304_one_active_turn_per_conversation ON seller_turns(conversation_id) WHERE status = 'processing'");
        DB::statement("ALTER TABLE message_reviews ADD CONSTRAINT e304_review_type_check CHECK (type IN ('unclear','bureaucratic','too_long','wrong_fact','wrong_tone','other'))");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS e304_immutable_conversation_channel ON conversations');
        DB::statement('DROP FUNCTION IF EXISTS e304_immutable_conversation_channel()');
        DB::statement('DROP TRIGGER IF EXISTS e304_verify_turn_origin ON seller_turns');
        DB::statement('DROP FUNCTION IF EXISTS e304_verify_turn_origin()');
        DB::statement('DROP TRIGGER IF EXISTS e304_immutable_message_origin ON messages');
        DB::statement('DROP FUNCTION IF EXISTS e304_immutable_message_origin()');
        foreach (['message_reviews', 'seller_tasks', 'seller_turns', 'messages', 'conversations', 'channel_inbound_events', 'brand_seller_settings', 'channel_accounts', 'contacts'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
