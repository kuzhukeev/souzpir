<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE c02_responder_stages (
  id char(26) PRIMARY KEY,
  organization_id char(26) NOT NULL,
  brand_id char(26) NOT NULL,
  conversation_id char(26) NOT NULL,
  turn_id char(26) NOT NULL UNIQUE REFERENCES seller_turns(id) ON DELETE RESTRICT,
  extractor_stage_id char(26) NOT NULL,
  first_state_id char(26) NOT NULL,
  release_id char(26) NOT NULL,
  request_encrypted text NOT NULL,
  request_digest char(64) NOT NULL CHECK (request_digest ~ '^[0-9a-f]{64}$'),
  seal char(64) NOT NULL CHECK (seal ~ '^[0-9a-f]{64}$'),
  ai_call_id char(26) NULL UNIQUE,
  gateway_request_hash char(64) NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  FOREIGN KEY (conversation_id, brand_id, organization_id)
    REFERENCES conversations(id, brand_id, organization_id) ON DELETE RESTRICT,
  FOREIGN KEY (release_id, brand_id, organization_id)
    REFERENCES knowledge_releases(id, brand_id, organization_id) ON DELETE RESTRICT,
  FOREIGN KEY (ai_call_id, organization_id, brand_id)
    REFERENCES ai_calls(id, organization_id, brand_id) ON DELETE RESTRICT,
  CHECK ((ai_call_id IS NULL AND gateway_request_hash IS NULL) OR
         (ai_call_id IS NOT NULL AND gateway_request_hash ~ '^[0-9a-f]{64}$'))
);
CREATE OR REPLACE FUNCTION c02_responder_stage_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    IF NEW.ai_call_id IS NOT NULL OR NEW.gateway_request_hash IS NOT NULL OR NOT EXISTS (
      SELECT 1 FROM c02_extractor_stages s JOIN c02_first_turn_states p ON p.extractor_stage_id = s.id
        JOIN seller_turns t ON t.id = s.turn_id
      WHERE s.id = NEW.extractor_stage_id AND p.id = NEW.first_state_id
        AND s.organization_id = NEW.organization_id AND s.brand_id = NEW.brand_id
        AND s.conversation_id = NEW.conversation_id AND s.turn_id = NEW.turn_id
        AND s.release_id = NEW.release_id AND p.release_id = NEW.release_id
        AND p.organization_id = NEW.organization_id AND p.brand_id = NEW.brand_id
        AND p.conversation_id = NEW.conversation_id AND p.turn_id = NEW.turn_id
        AND t.status = 'processing' AND t.base_version = 0 AND t.base_history_revision = 1 AND t.cutoff_sequence = 1
    ) THEN
      RAISE EXCEPTION 'Invalid protected responder source' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
  END IF;
  IF TG_OP = 'DELETE' OR ROW(NEW.id, NEW.organization_id, NEW.brand_id, NEW.conversation_id, NEW.turn_id,
      NEW.extractor_stage_id, NEW.first_state_id, NEW.release_id, NEW.request_encrypted, NEW.request_digest, NEW.seal, NEW.created_at)
    IS DISTINCT FROM ROW(OLD.id, OLD.organization_id, OLD.brand_id, OLD.conversation_id, OLD.turn_id,
      OLD.extractor_stage_id, OLD.first_state_id, OLD.release_id, OLD.request_encrypted, OLD.request_digest, OLD.seal, OLD.created_at)
    OR OLD.ai_call_id IS NOT NULL OR OLD.gateway_request_hash IS NOT NULL
    OR NEW.ai_call_id IS NULL OR NEW.gateway_request_hash IS NULL OR NOT EXISTS (
      SELECT 1 FROM ai_calls a WHERE a.id = NEW.ai_call_id AND a.organization_id = OLD.organization_id
        AND a.brand_id = OLD.brand_id AND a.object_type = 'conversation' AND a.object_id = OLD.conversation_id
        AND a.family = 'seller' AND a.purpose = 'responder' AND a.request_hash = NEW.gateway_request_hash
        AND a.c02_creation_xid = pg_current_xact_id() AND a.c02_reservation_digest IS NOT NULL
        AND current_setting('c02.reservation_nonce', true) ~ '^[0-9a-f]{64}$'
        AND a.c02_reservation_digest = encode(sha256(convert_to('c02-reservation:' || current_setting('c02.reservation_nonce', true), 'UTF8')), 'hex')
    ) THEN
    RAISE EXCEPTION 'Protected responder stage is immutable' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END;
$$;
CREATE TRIGGER c02_responder_stage_guard BEFORE INSERT OR UPDATE OR DELETE ON c02_responder_stages
  FOR EACH ROW EXECUTE FUNCTION c02_responder_stage_guard();
CREATE OR REPLACE FUNCTION c02_responder_stage_no_truncate() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Protected responder history cannot be truncated' USING ERRCODE = '23514'; END;
$$;
CREATE TRIGGER c02_responder_stage_no_truncate BEFORE TRUNCATE ON c02_responder_stages
  FOR EACH STATEMENT EXECUTE FUNCTION c02_responder_stage_no_truncate();
SQL);
    }

    public function down(): void
    {
        if (DB::table('c02_responder_stages')->exists()) {
            throw new RuntimeException('Cannot discard protected responder stage history.');
        }
        Schema::dropIfExists('c02_responder_stages');
        DB::unprepared('DROP FUNCTION IF EXISTS c02_responder_stage_guard(); DROP FUNCTION IF EXISTS c02_responder_stage_no_truncate();');
    }
};
