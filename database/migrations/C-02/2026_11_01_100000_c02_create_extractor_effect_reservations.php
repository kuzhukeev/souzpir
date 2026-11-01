<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE c02_extractor_effect_reservations (
  id char(26) PRIMARY KEY,
  organization_id char(26) NOT NULL,
  brand_id char(26) NOT NULL,
  conversation_id char(26) NOT NULL,
  turn_id char(26) NOT NULL REFERENCES seller_turns(id) ON DELETE RESTRICT,
  -- The stage has its own immutable-history triggers; a direct FK would
  -- intercept its established TRUNCATE guard before it can reject safely.
  extractor_stage_id char(26) NOT NULL UNIQUE,
  ai_call_id char(26) NOT NULL UNIQUE,
  request_hash char(64) NOT NULL CHECK (request_hash ~ '^[0-9a-f]{64}$'),
  seal char(64) NOT NULL CHECK (seal ~ '^[0-9a-f]{64}$'),
  created_at timestamptz NOT NULL DEFAULT now(),
  FOREIGN KEY (conversation_id, brand_id, organization_id)
    REFERENCES conversations(id, brand_id, organization_id) ON DELETE RESTRICT,
  FOREIGN KEY (ai_call_id, organization_id, brand_id)
    REFERENCES ai_calls(id, organization_id, brand_id) ON DELETE RESTRICT
);
CREATE OR REPLACE FUNCTION c02_extractor_effect_reservation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP <> 'INSERT' THEN
    RAISE EXCEPTION 'Extractor effect reservation history is immutable' USING ERRCODE = '23514';
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM c02_extractor_stages s
    JOIN seller_turns t ON t.id = s.turn_id
    JOIN ai_calls a ON a.id = s.ai_call_id
    WHERE s.id = NEW.extractor_stage_id AND s.organization_id = NEW.organization_id
      AND s.brand_id = NEW.brand_id AND s.conversation_id = NEW.conversation_id
      AND s.turn_id = NEW.turn_id AND s.ai_call_id = NEW.ai_call_id
      AND s.gateway_request_hash = NEW.request_hash AND t.status = 'processing'
      AND t.organization_id = NEW.organization_id AND t.brand_id = NEW.brand_id
      AND t.conversation_id = NEW.conversation_id AND a.organization_id = NEW.organization_id
      AND a.brand_id = NEW.brand_id AND a.object_type = 'conversation' AND a.object_id = NEW.conversation_id
      AND a.family = 'seller' AND a.purpose = 'extractor' AND a.status = 'reserved' AND a.request_hash = NEW.request_hash
      AND a.c02_creation_xid = pg_current_xact_id() AND a.c02_reservation_digest IS NOT NULL
      AND current_setting('c02.reservation_nonce', true) ~ '^[0-9a-f]{64}$'
      AND a.c02_reservation_digest = encode(sha256(convert_to('c02-reservation:' || current_setting('c02.reservation_nonce', true), 'UTF8')), 'hex')
  ) THEN
    RAISE EXCEPTION 'Extractor effect reservation requires the original AI reservation transaction' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END;
$$;
CREATE TRIGGER c02_extractor_effect_reservation_guard BEFORE INSERT OR UPDATE OR DELETE ON c02_extractor_effect_reservations
  FOR EACH ROW EXECUTE FUNCTION c02_extractor_effect_reservation_guard();
CREATE OR REPLACE FUNCTION c02_extractor_effect_reservation_no_truncate() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Extractor effect reservation history cannot be truncated' USING ERRCODE = '23514'; END;
$$;
CREATE TRIGGER c02_extractor_effect_reservation_no_truncate BEFORE TRUNCATE ON c02_extractor_effect_reservations
  FOR EACH STATEMENT EXECUTE FUNCTION c02_extractor_effect_reservation_no_truncate();

-- Existing bound stages are deliberately not backfilled. Every new binding
-- after this migration must commit its reservation evidence atomically.
CREATE OR REPLACE FUNCTION c02_require_extractor_effect_reservation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM c02_extractor_effect_reservations r
    WHERE r.extractor_stage_id = NEW.id AND r.ai_call_id = NEW.ai_call_id
      AND r.organization_id = NEW.organization_id AND r.brand_id = NEW.brand_id
      AND r.conversation_id = NEW.conversation_id AND r.turn_id = NEW.turn_id
      AND r.request_hash = NEW.gateway_request_hash
  ) THEN
    RAISE EXCEPTION 'Protected extractor binding requires effect reservation evidence' USING ERRCODE = '23514';
  END IF;
  RETURN NULL;
END;
$$;
CREATE CONSTRAINT TRIGGER c02_require_extractor_effect_reservation
  AFTER UPDATE ON c02_extractor_stages DEFERRABLE INITIALLY DEFERRED
  FOR EACH ROW WHEN (OLD.ai_call_id IS NULL AND NEW.ai_call_id IS NOT NULL)
  EXECUTE FUNCTION c02_require_extractor_effect_reservation();
SQL);
    }

    public function down(): void
    {
        if (DB::table('c02_extractor_effect_reservations')->exists()) {
            throw new RuntimeException('Cannot discard extractor effect reservation history.');
        }
        DB::unprepared('DROP TRIGGER c02_require_extractor_effect_reservation ON c02_extractor_stages; DROP FUNCTION c02_require_extractor_effect_reservation();');
        Schema::dropIfExists('c02_extractor_effect_reservations');
        DB::unprepared('DROP FUNCTION IF EXISTS c02_extractor_effect_reservation_guard(); DROP FUNCTION IF EXISTS c02_extractor_effect_reservation_no_truncate();');
    }
};
