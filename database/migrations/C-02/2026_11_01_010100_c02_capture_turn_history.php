<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE seller_turns ADD COLUMN base_history_revision bigint NULL,
  ADD COLUMN cutoff_sequence bigint NULL,
  ADD CONSTRAINT c02_turn_history_pair CHECK (
    (base_history_revision IS NULL AND cutoff_sequence IS NULL) OR
    (base_history_revision IS NOT NULL AND base_history_revision >= 0 AND cutoff_sequence IS NOT NULL AND cutoff_sequence > 0)
  );

CREATE OR REPLACE FUNCTION c02_capture_turn_history() RETURNS trigger AS $$
DECLARE
  current_revision bigint;
  current_sequence bigint;
  input_sequence bigint;
  ready boolean;
BEGIN
  IF TG_OP = 'UPDATE' THEN
    IF ROW(NEW.organization_id, NEW.brand_id, NEW.conversation_id, NEW.input_message_id,
           NEW.external_event_id, NEW.idempotency_key, NEW.request_hash, NEW.claim_hash,
           NEW.base_version, NEW.base_history_revision, NEW.cutoff_sequence)
       IS DISTINCT FROM
       ROW(OLD.organization_id, OLD.brand_id, OLD.conversation_id, OLD.input_message_id,
           OLD.external_event_id, OLD.idempotency_key, OLD.request_hash, OLD.claim_hash,
           OLD.base_version, OLD.base_history_revision, OLD.cutoff_sequence) THEN
      RAISE EXCEPTION 'Turn identity and history snapshot are immutable' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
  END IF;
  IF NEW.base_history_revision IS NOT NULL OR NEW.cutoff_sequence IS NOT NULL THEN
    RAISE EXCEPTION 'Turn history snapshot is managed by the database' USING ERRCODE = '23514';
  END IF;
  SELECT history_revision, history_sequence, history_ready INTO current_revision, current_sequence, ready
    FROM conversations WHERE id = NEW.conversation_id AND organization_id = NEW.organization_id AND brand_id = NEW.brand_id FOR UPDATE;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'Turn conversation missing' USING ERRCODE = '23503';
  END IF;
  SELECT history_sequence INTO input_sequence FROM messages WHERE id = NEW.input_message_id
    AND conversation_id = NEW.conversation_id AND organization_id = NEW.organization_id AND brand_id = NEW.brand_id;
  -- A stale or legacy input is not evidence of a current ordered history. Leave
  -- the snapshot NULL; a future finalizer must reject it, never infer authority.
  IF ready AND input_sequence IS NOT NULL AND input_sequence = current_sequence AND current_sequence > 0 THEN
    NEW.base_history_revision := current_revision;
    NEW.cutoff_sequence := current_sequence;
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER c02_capture_turn_history BEFORE INSERT OR UPDATE ON seller_turns
  FOR EACH ROW EXECUTE FUNCTION c02_capture_turn_history();
SQL);
    }

    public function down(): void
    {
        if (DB::table('seller_turns')->whereNotNull('base_history_revision')->orWhereNotNull('cutoff_sequence')->exists()) {
            throw new RuntimeException('Cannot discard recorded turn history snapshots.');
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS c02_capture_turn_history ON seller_turns;
DROP FUNCTION IF EXISTS c02_capture_turn_history();
ALTER TABLE seller_turns DROP CONSTRAINT c02_turn_history_pair,
  DROP COLUMN base_history_revision, DROP COLUMN cutoff_sequence;
SQL);
    }
};
