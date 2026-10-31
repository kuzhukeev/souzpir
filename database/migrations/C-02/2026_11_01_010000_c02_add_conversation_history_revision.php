<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE conversations
  ADD COLUMN history_revision bigint NOT NULL DEFAULT 0,
  ADD COLUMN history_sequence bigint NOT NULL DEFAULT 0,
  ADD COLUMN history_ready boolean NOT NULL DEFAULT false,
  ADD CONSTRAINT c02_history_revision_nonnegative CHECK (history_revision >= 0),
  ADD CONSTRAINT c02_history_sequence_nonnegative CHECK (history_sequence >= 0);
-- Existing histories have no verified ordering and must not be silently trusted.
ALTER TABLE conversations ALTER COLUMN history_ready SET DEFAULT true;
ALTER TABLE messages ADD COLUMN history_sequence bigint NULL,
  ADD CONSTRAINT c02_message_history_sequence_positive CHECK (history_sequence IS NULL OR history_sequence > 0);
CREATE UNIQUE INDEX c02_message_history_sequence_unique ON messages (conversation_id, history_sequence)
  WHERE history_sequence IS NOT NULL;

CREATE OR REPLACE FUNCTION c02_conversation_history_revision() RETURNS trigger AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    IF NEW.history_revision <> 0 OR NEW.history_sequence <> 0 OR NEW.history_ready IS DISTINCT FROM true THEN
      RAISE EXCEPTION 'Invalid initial history revision' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
  END IF;
  IF NEW.history_ready IS DISTINCT FROM OLD.history_ready OR
     (pg_trigger_depth() < 2 AND (NEW.history_revision IS DISTINCT FROM OLD.history_revision OR
                                  NEW.history_sequence IS DISTINCT FROM OLD.history_sequence)) THEN
    RAISE EXCEPTION 'History revision is managed by the database' USING ERRCODE = '23514';
  END IF;
  IF ROW(OLD.organization_id, OLD.brand_id, OLD.channel_account_id, OLD.contact_id,
         OLD.stage, OLD.outcome, OLD.client_type, OLD.state, OLD.summary, OLD.mode,
         OLD.assigned_user_id, OLD.do_not_disturb, OLD.followups_sent, OLD.version,
         OLD.next_followup_at, OLD.last_client_message_at, OLD.last_bot_message_at,
         OLD.handed_off_at, OLD.taken_at)
     IS DISTINCT FROM
     ROW(NEW.organization_id, NEW.brand_id, NEW.channel_account_id, NEW.contact_id,
         NEW.stage, NEW.outcome, NEW.client_type, NEW.state, NEW.summary, NEW.mode,
         NEW.assigned_user_id, NEW.do_not_disturb, NEW.followups_sent, NEW.version,
         NEW.next_followup_at, NEW.last_client_message_at, NEW.last_bot_message_at,
         NEW.handed_off_at, NEW.taken_at) THEN
    NEW.history_revision := NEW.history_revision + 1;
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER c02_conversation_history_revision BEFORE INSERT OR UPDATE ON conversations
  FOR EACH ROW EXECUTE FUNCTION c02_conversation_history_revision();

CREATE OR REPLACE FUNCTION c02_message_history_revision() RETURNS trigger AS $$
DECLARE
  next_sequence bigint;
  parent_id char(26);
BEGIN
  IF TG_OP = 'INSERT' THEN
    IF NEW.history_sequence IS NOT NULL THEN
      RAISE EXCEPTION 'Message sequence is managed by the database' USING ERRCODE = '23514';
    END IF;
    parent_id := NEW.conversation_id;
  ELSE
    parent_id := OLD.conversation_id;
    IF TG_OP = 'UPDATE' AND (NEW.conversation_id IS DISTINCT FROM OLD.conversation_id OR
                             NEW.history_sequence IS DISTINCT FROM OLD.history_sequence) THEN
      RAISE EXCEPTION 'Message origin and sequence are immutable' USING ERRCODE = '23514';
    END IF;
  END IF;
  -- A message UPDATE/DELETE can already hold the message row. Never wait for
  -- the parent while holding it: 55P03 requires retrying the whole transaction.
  PERFORM 1 FROM conversations WHERE id = parent_id FOR UPDATE NOWAIT;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'Conversation missing' USING ERRCODE = '23503';
  END IF;
  IF TG_OP = 'INSERT' THEN
    UPDATE conversations SET history_sequence = history_sequence + 1,
      history_revision = history_revision + 1 WHERE id = parent_id RETURNING history_sequence INTO next_sequence;
    NEW.history_sequence := next_sequence;
    RETURN NEW;
  END IF;
  UPDATE conversations SET history_revision = history_revision + 1 WHERE id = parent_id;
  IF TG_OP = 'DELETE' THEN
    RETURN OLD;
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER c02_message_history_revision BEFORE INSERT OR UPDATE OR DELETE ON messages
  FOR EACH ROW EXECUTE FUNCTION c02_message_history_revision();

CREATE OR REPLACE FUNCTION c02_history_no_truncate() RETURNS trigger AS $$
BEGIN
  RAISE EXCEPTION 'Truncating message history is not supported' USING ERRCODE = '23514';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER c02_history_no_truncate BEFORE TRUNCATE ON messages
  FOR EACH STATEMENT EXECUTE FUNCTION c02_history_no_truncate();
SQL);
    }

    public function down(): void
    {
        if (DB::table('conversations')->where('history_revision', '>', 0)->orWhere('history_sequence', '>', 0)->exists()
            || DB::table('messages')->whereNotNull('history_sequence')->exists()) {
            throw new RuntimeException('Cannot discard recorded history ordering.');
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS c02_history_no_truncate ON messages;
DROP TRIGGER IF EXISTS c02_message_history_revision ON messages;
DROP TRIGGER IF EXISTS c02_conversation_history_revision ON conversations;
DROP FUNCTION IF EXISTS c02_history_no_truncate();
DROP FUNCTION IF EXISTS c02_message_history_revision();
DROP FUNCTION IF EXISTS c02_conversation_history_revision();
DROP INDEX IF EXISTS c02_message_history_sequence_unique;
ALTER TABLE messages DROP CONSTRAINT c02_message_history_sequence_positive, DROP COLUMN history_sequence;
ALTER TABLE conversations DROP CONSTRAINT c02_history_revision_nonnegative,
  DROP CONSTRAINT c02_history_sequence_nonnegative, DROP COLUMN history_revision,
  DROP COLUMN history_sequence, DROP COLUMN history_ready;
SQL);
    }
};
