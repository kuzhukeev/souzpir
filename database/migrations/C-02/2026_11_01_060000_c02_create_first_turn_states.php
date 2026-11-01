<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE c02_first_turn_states (
  id char(26) PRIMARY KEY,
  organization_id char(26) NOT NULL,
  brand_id char(26) NOT NULL,
  conversation_id char(26) NOT NULL,
  turn_id char(26) NOT NULL UNIQUE REFERENCES seller_turns(id) ON DELETE RESTRICT,
  -- The immutable stage is checked by the INSERT trigger below. A foreign key
  -- here would intercept stage TRUNCATE before its existing protective trigger.
  extractor_stage_id char(26) NOT NULL UNIQUE,
  ai_call_id char(26) NOT NULL UNIQUE,
  release_id char(26) NOT NULL,
  state_encrypted text NOT NULL,
  state_digest char(64) NOT NULL CHECK (state_digest ~ '^[0-9a-f]{64}$'),
  seal char(64) NOT NULL CHECK (seal ~ '^[0-9a-f]{64}$'),
  created_at timestamptz NOT NULL DEFAULT now(),
  FOREIGN KEY (conversation_id, brand_id, organization_id)
    REFERENCES conversations(id, brand_id, organization_id) ON DELETE RESTRICT,
  FOREIGN KEY (ai_call_id, organization_id, brand_id)
    REFERENCES ai_calls(id, organization_id, brand_id) ON DELETE RESTRICT,
  FOREIGN KEY (release_id, brand_id, organization_id)
    REFERENCES knowledge_releases(id, brand_id, organization_id) ON DELETE RESTRICT
);
CREATE OR REPLACE FUNCTION c02_first_turn_state_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP <> 'INSERT' THEN
    RAISE EXCEPTION 'Protected state history is immutable' USING ERRCODE = '23514';
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM c02_extractor_stages s JOIN seller_turns t ON t.id = s.turn_id
      JOIN ai_calls a ON a.id = s.ai_call_id
    WHERE s.id = NEW.extractor_stage_id AND s.organization_id = NEW.organization_id
      AND s.brand_id = NEW.brand_id AND s.conversation_id = NEW.conversation_id
      AND s.turn_id = NEW.turn_id AND s.ai_call_id = NEW.ai_call_id AND s.release_id = NEW.release_id
      AND t.status = 'processing' AND t.base_version = 0 AND t.base_history_revision = 1 AND t.cutoff_sequence = 1
      AND a.status = 'completed' AND a.organization_id = NEW.organization_id AND a.brand_id = NEW.brand_id
  ) THEN
    RAISE EXCEPTION 'Invalid protected state origin' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END;
$$;
CREATE TRIGGER c02_first_turn_state_guard BEFORE INSERT OR UPDATE OR DELETE ON c02_first_turn_states
  FOR EACH ROW EXECUTE FUNCTION c02_first_turn_state_guard();
CREATE OR REPLACE FUNCTION c02_first_turn_state_no_truncate() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Protected state history cannot be truncated' USING ERRCODE = '23514'; END;
$$;
CREATE TRIGGER c02_first_turn_state_no_truncate BEFORE TRUNCATE ON c02_first_turn_states
  FOR EACH STATEMENT EXECUTE FUNCTION c02_first_turn_state_no_truncate();
SQL);
    }

    public function down(): void
    {
        if (DB::table('c02_first_turn_states')->exists()) {
            throw new RuntimeException('Cannot discard protected state history.');
        }
        Schema::dropIfExists('c02_first_turn_states');
        DB::unprepared('DROP FUNCTION IF EXISTS c02_first_turn_state_guard(); DROP FUNCTION IF EXISTS c02_first_turn_state_no_truncate();');
    }
};
