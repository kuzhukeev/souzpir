<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE seller_turns DROP CONSTRAINT e304_turn_status_check;
ALTER TABLE seller_turns ADD CONSTRAINT e304_turn_status_check
  CHECK (status IN ('processing','uncertain','completed','suggested','suppressed','failed'));
DROP INDEX e304_one_active_turn_per_conversation;
CREATE UNIQUE INDEX e304_one_active_turn_per_conversation ON seller_turns(conversation_id)
  WHERE status IN ('processing','uncertain');
-- Reject inconsistent legacy processing rows instead of losing evidence or
-- accepting a state that would make a later inbound message roll back.
ALTER TABLE seller_turns ADD CONSTRAINT c02_pending_has_no_outcome
  CHECK (status NOT IN ('processing','uncertain') OR (outcome IS NULL AND outcome_hash IS NULL));

-- Preserve unknown pre-existing results; never guess that an external effect failed.
UPDATE seller_turns t SET status = 'uncertain', updated_at = now()
  FROM conversations c
  WHERE t.conversation_id = c.id AND t.organization_id = c.organization_id AND t.brand_id = c.brand_id
    AND t.status = 'processing' AND (
      NOT c.history_ready OR t.base_history_revision IS NULL OR t.cutoff_sequence IS NULL
      OR t.base_history_revision <> c.history_revision OR t.cutoff_sequence <> c.history_sequence
      OR t.base_version <> c.version
      OR NOT EXISTS (SELECT 1 FROM messages m WHERE m.id = t.input_message_id
        AND m.conversation_id = t.conversation_id AND m.organization_id = t.organization_id
        AND m.brand_id = t.brand_id AND m.role = 'client' AND m.history_sequence = t.cutoff_sequence)
    );
CREATE OR REPLACE FUNCTION c02_uncertain_turn_is_immutable() RETURNS trigger AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    IF OLD.status = 'uncertain' THEN
      RAISE EXCEPTION 'Uncertain turn requires a verified manual recovery workflow' USING ERRCODE = '23514';
    END IF;
    RETURN OLD;
  END IF;
  IF OLD.status = 'uncertain' AND (NEW.status IS DISTINCT FROM OLD.status
      OR NEW.outcome IS DISTINCT FROM OLD.outcome OR NEW.outcome_hash IS DISTINCT FROM OLD.outcome_hash) THEN
    RAISE EXCEPTION 'Uncertain turn requires a verified manual recovery workflow' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER c02_uncertain_turn_is_immutable BEFORE UPDATE OR DELETE ON seller_turns
  FOR EACH ROW EXECUTE FUNCTION c02_uncertain_turn_is_immutable();

CREATE OR REPLACE FUNCTION c02_uncertain_turn_no_truncate() RETURNS trigger AS $$
BEGIN
  IF EXISTS (SELECT 1 FROM seller_turns WHERE status = 'uncertain') THEN
    RAISE EXCEPTION 'Uncertain turn requires a verified manual recovery workflow' USING ERRCODE = '23514';
  END IF;
  RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER c02_uncertain_turn_no_truncate BEFORE TRUNCATE ON seller_turns
  FOR EACH STATEMENT EXECUTE FUNCTION c02_uncertain_turn_no_truncate();

CREATE OR REPLACE FUNCTION c02_inbound_marks_active_turn_uncertain() RETURNS trigger AS $$
BEGIN
  -- Earlier message triggers hold the parent conversation lock. The update
  -- and the new input commit together, serialized against commitTurn.
  IF NEW.role = 'client' THEN
    UPDATE seller_turns SET status = 'uncertain', updated_at = now()
      WHERE organization_id = NEW.organization_id AND brand_id = NEW.brand_id
        AND conversation_id = NEW.conversation_id AND status = 'processing';
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER c02_inbound_marks_active_turn_uncertain AFTER INSERT ON messages
  FOR EACH ROW EXECUTE FUNCTION c02_inbound_marks_active_turn_uncertain();
SQL);
    }

    public function down(): void
    {
        if (DB::table('seller_turns')->where('status', 'uncertain')->exists()) {
            throw new RuntimeException('Cannot discard unresolved uncertain turns.');
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER c02_inbound_marks_active_turn_uncertain ON messages;
DROP FUNCTION c02_inbound_marks_active_turn_uncertain();
DROP TRIGGER c02_uncertain_turn_is_immutable ON seller_turns;
DROP FUNCTION c02_uncertain_turn_is_immutable();
DROP TRIGGER c02_uncertain_turn_no_truncate ON seller_turns;
DROP FUNCTION c02_uncertain_turn_no_truncate();
ALTER TABLE seller_turns DROP CONSTRAINT c02_pending_has_no_outcome;
DROP INDEX e304_one_active_turn_per_conversation;
CREATE UNIQUE INDEX e304_one_active_turn_per_conversation ON seller_turns(conversation_id) WHERE status = 'processing';
ALTER TABLE seller_turns DROP CONSTRAINT e304_turn_status_check;
ALTER TABLE seller_turns ADD CONSTRAINT e304_turn_status_check
  CHECK (status IN ('processing','completed','suggested','suppressed','failed'));
SQL);
    }
};
