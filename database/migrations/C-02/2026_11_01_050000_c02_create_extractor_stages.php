<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE c02_extractor_stages (
  id char(26) PRIMARY KEY,
  organization_id char(26) NOT NULL,
  brand_id char(26) NOT NULL,
  conversation_id char(26) NOT NULL,
  turn_id char(26) NOT NULL REFERENCES seller_turns(id) ON DELETE RESTRICT,
  actor_id char(26) NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  session_hash char(64) NOT NULL,
  selection_revision bigint NOT NULL CHECK (selection_revision >= 0),
  history_revision bigint NOT NULL CHECK (history_revision >= 0),
  cutoff_sequence bigint NOT NULL CHECK (cutoff_sequence > 0),
  base_version integer NOT NULL CHECK (base_version >= 0),
  release_id char(26) NOT NULL,
  release_checksum char(64) NOT NULL,
  input_encrypted text NOT NULL,
  input_digest char(64) NOT NULL,
  seal char(64) NOT NULL,
  ai_call_id char(26) NULL UNIQUE,
  gateway_request_hash char(64) NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (turn_id),
  FOREIGN KEY (conversation_id, brand_id, organization_id)
    REFERENCES conversations(id, brand_id, organization_id) ON DELETE RESTRICT,
  FOREIGN KEY (release_id, brand_id, organization_id)
    REFERENCES knowledge_releases(id, brand_id, organization_id) ON DELETE RESTRICT,
  FOREIGN KEY (ai_call_id, organization_id, brand_id)
    REFERENCES ai_calls(id, organization_id, brand_id) ON DELETE RESTRICT,
  CHECK (session_hash ~ '^[0-9a-f]{64}$' AND release_checksum ~ '^[0-9a-f]{64}$'
    AND input_digest ~ '^[0-9a-f]{64}$' AND seal ~ '^[0-9a-f]{64}$'),
  CHECK ((ai_call_id IS NULL AND gateway_request_hash IS NULL) OR
         (ai_call_id IS NOT NULL AND gateway_request_hash ~ '^[0-9a-f]{64}$'))
);
CREATE OR REPLACE FUNCTION c02_extractor_stage_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    IF NEW.ai_call_id IS NOT NULL OR NEW.gateway_request_hash IS NOT NULL OR NOT EXISTS (
      SELECT 1 FROM seller_turns t WHERE t.id = NEW.turn_id AND t.organization_id = NEW.organization_id
        AND t.brand_id = NEW.brand_id AND t.conversation_id = NEW.conversation_id
        AND t.base_version = NEW.base_version AND t.base_history_revision = NEW.history_revision
        AND t.cutoff_sequence = NEW.cutoff_sequence
    ) THEN
      RAISE EXCEPTION 'Invalid protected extractor binding' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
  END IF;
  IF TG_OP = 'DELETE' OR ROW(NEW.id, NEW.organization_id, NEW.brand_id, NEW.conversation_id,
      NEW.turn_id, NEW.actor_id, NEW.session_hash, NEW.selection_revision, NEW.history_revision, NEW.cutoff_sequence,
      NEW.base_version, NEW.release_id, NEW.release_checksum, NEW.input_encrypted, NEW.input_digest, NEW.seal, NEW.created_at)
      IS DISTINCT FROM ROW(OLD.id, OLD.organization_id, OLD.brand_id, OLD.conversation_id,
      OLD.turn_id, OLD.actor_id, OLD.session_hash, OLD.selection_revision, OLD.history_revision, OLD.cutoff_sequence,
      OLD.base_version, OLD.release_id, OLD.release_checksum, OLD.input_encrypted, OLD.input_digest, OLD.seal, OLD.created_at)
      OR OLD.ai_call_id IS NOT NULL OR OLD.gateway_request_hash IS NOT NULL
      OR NEW.ai_call_id IS NULL OR NEW.gateway_request_hash IS NULL OR NOT EXISTS (
        SELECT 1 FROM ai_calls a WHERE a.id = NEW.ai_call_id AND a.organization_id = OLD.organization_id
          AND a.brand_id = OLD.brand_id AND a.object_type = 'conversation' AND a.object_id = OLD.conversation_id
          AND a.family = 'seller' AND a.purpose = 'extractor' AND a.request_hash = NEW.gateway_request_hash
          AND a.c02_creation_xid = pg_current_xact_id() AND a.c02_reservation_digest IS NOT NULL
          AND current_setting('c02.reservation_nonce', true) ~ '^[0-9a-f]{64}$'
          AND a.c02_reservation_digest = encode(sha256(convert_to('c02-reservation:' || current_setting('c02.reservation_nonce', true), 'UTF8')), 'hex')
      ) THEN
    RAISE EXCEPTION 'Protected extractor stage is immutable' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END;
$$;
CREATE TRIGGER c02_extractor_stage_guard BEFORE INSERT OR UPDATE OR DELETE ON c02_extractor_stages
  FOR EACH ROW EXECUTE FUNCTION c02_extractor_stage_guard();
CREATE OR REPLACE FUNCTION c02_extractor_stage_no_truncate() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Protected extractor history cannot be truncated' USING ERRCODE = '23514'; END;
$$;
CREATE TRIGGER c02_extractor_stage_no_truncate BEFORE TRUNCATE ON c02_extractor_stages
  FOR EACH STATEMENT EXECUTE FUNCTION c02_extractor_stage_no_truncate();
SQL);
    }

    public function down(): void
    {
        if (DB::table('c02_extractor_stages')->exists()) {
            throw new RuntimeException('Cannot discard protected extractor stage history.');
        }
        Schema::dropIfExists('c02_extractor_stages');
        DB::unprepared('DROP FUNCTION IF EXISTS c02_extractor_stage_guard(); DROP FUNCTION IF EXISTS c02_extractor_stage_no_truncate();');
    }
};
