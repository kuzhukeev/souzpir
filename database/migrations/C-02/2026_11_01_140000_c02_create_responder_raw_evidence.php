<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE c02_responder_stages ADD CONSTRAINT c02_responder_stage_call_key UNIQUE (id, ai_call_id);
CREATE TABLE c02_responder_raw_evidence (
  stage_id char(26) PRIMARY KEY,
  ai_call_id char(26) NOT NULL UNIQUE,
  payload_encrypted text NOT NULL,
  created_at timestamp(0) without time zone NOT NULL,
  FOREIGN KEY (stage_id, ai_call_id) REFERENCES c02_responder_stages(id, ai_call_id) ON DELETE RESTRICT
);
SQL);
    }

    public function down(): void
    {
        if (DB::table('c02_responder_raw_evidence')->exists()) {
            throw new RuntimeException('Cannot discard responder raw evidence.');
        }
        DB::statement('DROP TABLE c02_responder_raw_evidence');
        DB::statement('ALTER TABLE c02_responder_stages DROP CONSTRAINT c02_responder_stage_call_key');
    }
};
