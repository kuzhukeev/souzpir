<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- Existing turns have no proven creation transaction and cannot be upgraded.
ALTER TABLE seller_turns ADD COLUMN c02_claim_creation_xid xid8 NULL;
ALTER TABLE seller_turns ADD COLUMN c02_claim_reservation_digest char(64) NULL;
CREATE OR REPLACE FUNCTION c02_turn_claim_creation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE reservation_nonce text;
BEGIN
  IF TG_OP = 'INSERT' THEN
    NEW.c02_claim_creation_xid := pg_current_xact_id();
    reservation_nonce := replace(gen_random_uuid()::text, '-', '') || replace(gen_random_uuid()::text, '-', '');
    PERFORM set_config('c02.turn_claim_nonce', reservation_nonce, true);
    NEW.c02_claim_reservation_digest := encode(sha256(convert_to('c02-turn-claim:' || reservation_nonce, 'UTF8')), 'hex');
  ELSIF NEW.c02_claim_creation_xid IS DISTINCT FROM OLD.c02_claim_creation_xid
     OR NEW.c02_claim_reservation_digest IS DISTINCT FROM OLD.c02_claim_reservation_digest THEN
    RAISE EXCEPTION 'Turn creation provenance is immutable' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END;
$$;
CREATE TRIGGER c02_turn_claim_creation_guard BEFORE INSERT OR UPDATE ON seller_turns
  FOR EACH ROW EXECUTE FUNCTION c02_turn_claim_creation_guard();

CREATE TABLE c02_protected_turn_claims (
  id char(26) PRIMARY KEY,
  organization_id char(26) NOT NULL,
  brand_id char(26) NOT NULL,
  conversation_id char(26) NOT NULL,
  turn_id char(26) NOT NULL UNIQUE REFERENCES seller_turns(id) ON DELETE RESTRICT,
  actor_id char(26) NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  session_hash char(64) NOT NULL CHECK (session_hash ~ '^[0-9a-f]{64}$'),
  selection_revision bigint NOT NULL CHECK (selection_revision >= 0),
  request_digest char(64) NOT NULL CHECK (request_digest ~ '^[0-9a-f]{64}$'),
  claim_hash char(64) NOT NULL CHECK (claim_hash ~ '^[0-9a-f]{64}$'),
  seal char(64) NOT NULL CHECK (seal ~ '^[0-9a-f]{64}$'),
  created_at timestamptz NOT NULL DEFAULT now(),
  FOREIGN KEY (conversation_id, brand_id, organization_id)
    REFERENCES conversations(id, brand_id, organization_id) ON DELETE RESTRICT
);
CREATE OR REPLACE FUNCTION c02_protected_turn_claim_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP <> 'INSERT' THEN
    RAISE EXCEPTION 'Protected claim history is immutable' USING ERRCODE = '23514';
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM seller_turns t
    WHERE t.id = NEW.turn_id AND t.organization_id = NEW.organization_id
      AND t.brand_id = NEW.brand_id AND t.conversation_id = NEW.conversation_id
      AND t.status = 'processing' AND t.base_version = 0
      AND t.base_history_revision = 1 AND t.cutoff_sequence = 1
      AND t.claim_hash = NEW.claim_hash AND t.c02_claim_creation_xid = pg_current_xact_id()
      AND current_setting('c02.turn_claim_nonce', true) ~ '^[0-9a-f]{64}$'
      AND t.c02_claim_reservation_digest = encode(sha256(convert_to('c02-turn-claim:' || current_setting('c02.turn_claim_nonce', true), 'UTF8')), 'hex')
  ) THEN
    RAISE EXCEPTION 'Protected claim must originate with the new turn' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END;
$$;
CREATE TRIGGER c02_protected_turn_claim_guard BEFORE INSERT OR UPDATE OR DELETE ON c02_protected_turn_claims
  FOR EACH ROW EXECUTE FUNCTION c02_protected_turn_claim_guard();
CREATE OR REPLACE FUNCTION c02_protected_turn_claim_no_truncate() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Protected claim history cannot be truncated' USING ERRCODE = '23514'; END;
$$;
CREATE TRIGGER c02_protected_turn_claim_no_truncate BEFORE TRUNCATE ON c02_protected_turn_claims
  FOR EACH STATEMENT EXECUTE FUNCTION c02_protected_turn_claim_no_truncate();
SQL);
    }

    public function down(): void
    {
        if (DB::table('c02_protected_turn_claims')->exists()) {
            throw new RuntimeException('Cannot discard protected claim history.');
        }
        Schema::dropIfExists('c02_protected_turn_claims');
        DB::unprepared(<<<'SQL'
DROP FUNCTION IF EXISTS c02_protected_turn_claim_guard();
DROP FUNCTION IF EXISTS c02_protected_turn_claim_no_truncate();
DROP TRIGGER c02_turn_claim_creation_guard ON seller_turns;
DROP FUNCTION c02_turn_claim_creation_guard();
ALTER TABLE seller_turns DROP COLUMN c02_claim_reservation_digest;
ALTER TABLE seller_turns DROP COLUMN c02_claim_creation_xid;
SQL);
    }
};
