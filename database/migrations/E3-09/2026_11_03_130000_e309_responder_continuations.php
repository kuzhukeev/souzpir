<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE c02_responder_stages DROP CONSTRAINT c02_responder_stages_turn_id_key');
        DB::statement('CREATE INDEX c02_responder_stages_turn_lookup ON c02_responder_stages (turn_id, created_at DESC, id DESC)');
        DB::statement(<<<'SQL'
CREATE TABLE e309_responder_continuations (
  stage_id char(26) PRIMARY KEY REFERENCES c02_responder_stages(id) ON DELETE RESTRICT,
  previous_stage_id char(26) NOT NULL UNIQUE REFERENCES c02_responder_stages(id) ON DELETE RESTRICT,
  payload_encrypted text NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now()
)
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS e309_responder_continuations');
        DB::statement('DROP INDEX IF EXISTS c02_responder_stages_turn_lookup');
        DB::statement('ALTER TABLE c02_responder_stages ADD CONSTRAINT c02_responder_stages_turn_id_key UNIQUE (turn_id)');
    }
};
