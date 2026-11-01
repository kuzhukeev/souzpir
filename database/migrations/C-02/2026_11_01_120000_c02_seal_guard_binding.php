<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE c02_guard_stages ADD COLUMN binding_seal char(64) NULL;
ALTER TABLE c02_guard_stages ADD CONSTRAINT c02_guard_binding_seal_shape
  CHECK (binding_seal IS NULL OR binding_seal ~ '^[0-9a-f]{64}$');
CREATE OR REPLACE FUNCTION c02_guard_binding_seal_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    IF NEW.binding_seal IS NOT NULL THEN
      RAISE EXCEPTION 'Guard seal requires a new binding' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
  END IF;
  IF OLD.ai_call_id IS NULL AND NEW.ai_call_id IS NOT NULL THEN
    IF OLD.binding_seal IS NOT NULL OR NEW.binding_seal IS NULL THEN
      RAISE EXCEPTION 'Guard binding requires a seal' USING ERRCODE = '23514';
    END IF;
  ELSIF NEW.binding_seal IS DISTINCT FROM OLD.binding_seal THEN
    RAISE EXCEPTION 'Guard binding seal is immutable' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END;
$$;
CREATE TRIGGER c02_guard_binding_seal_guard BEFORE INSERT OR UPDATE ON c02_guard_stages
  FOR EACH ROW EXECUTE FUNCTION c02_guard_binding_seal_guard();
SQL);
    }

    public function down(): void
    {
        if (DB::table('c02_guard_stages')->whereNotNull('binding_seal')->exists()) {
            throw new RuntimeException('Cannot discard guard binding seals.');
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER c02_guard_binding_seal_guard ON c02_guard_stages;
DROP FUNCTION c02_guard_binding_seal_guard();
ALTER TABLE c02_guard_stages DROP CONSTRAINT c02_guard_binding_seal_shape;
ALTER TABLE c02_guard_stages DROP COLUMN binding_seal;
SQL);
    }
};
