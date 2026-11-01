<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('c02_manual_review_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->ulid('conversation_id');
            $table->foreignUlid('turn_id')->unique()->constrained('seller_turns')->restrictOnDelete();
            $table->foreignUlid('actor_id')->constrained('users')->restrictOnDelete();
            $table->char('session_hash', 64);
            $table->string('reason', 32);
            $table->char('seal', 64);
            $table->timestampTz('created_at');
            $table->foreign(['conversation_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('conversations')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE c02_manual_review_requests ADD CONSTRAINT c02_manual_review_fields CHECK (reason = 'outcome_unconfirmed' AND session_hash ~ '^[0-9a-f]{64}$' AND seal ~ '^[0-9a-f]{64}$')");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION c02_manual_review_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM seller_turns t WHERE t.id = NEW.turn_id
      AND t.organization_id = NEW.organization_id AND t.brand_id = NEW.brand_id
      AND t.conversation_id = NEW.conversation_id AND t.status = 'uncertain' FOR UPDATE) THEN
    RAISE EXCEPTION 'Review requires an uncertain turn' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END;
$$;
CREATE TRIGGER c02_manual_review_insert_guard BEFORE INSERT ON c02_manual_review_requests
  FOR EACH ROW EXECUTE FUNCTION c02_manual_review_insert_guard();
CREATE OR REPLACE FUNCTION c02_manual_review_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION 'Manual review request is immutable' USING ERRCODE = '23514';
END;
$$;
CREATE TRIGGER c02_manual_review_immutable BEFORE UPDATE OR DELETE ON c02_manual_review_requests
  FOR EACH ROW EXECUTE FUNCTION c02_manual_review_immutable();
CREATE TRIGGER c02_manual_review_no_truncate BEFORE TRUNCATE ON c02_manual_review_requests
  FOR EACH STATEMENT EXECUTE FUNCTION c02_manual_review_immutable();
SQL);
    }

    public function down(): void
    {
        if (DB::table('c02_manual_review_requests')->exists()) {
            throw new LogicException('Cannot discard manual review requests.');
        }
        Schema::dropIfExists('c02_manual_review_requests');
        DB::unprepared('DROP FUNCTION IF EXISTS c02_manual_review_insert_guard(); DROP FUNCTION IF EXISTS c02_manual_review_immutable();');
    }
};
