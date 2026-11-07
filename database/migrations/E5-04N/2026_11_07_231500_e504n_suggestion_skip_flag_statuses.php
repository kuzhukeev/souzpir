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
  CHECK (status IN ('processing','uncertain','completed','suggested','suppressed','failed','sent','edited','skipped','flagged'));
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE seller_turns DROP CONSTRAINT e304_turn_status_check;
ALTER TABLE seller_turns ADD CONSTRAINT e304_turn_status_check
  CHECK (status IN ('processing','uncertain','completed','suggested','suppressed','failed','sent','edited'));
SQL);
    }
};
