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
  CHECK (status IN ('processing','uncertain','completed','suggested','suppressed','failed','sent','edited'));

ALTER TABLE seller_turns
  ADD COLUMN sent_by_user_id char(26) NULL REFERENCES users(id) ON DELETE RESTRICT,
  ADD COLUMN sent_at timestamptz NULL,
  ADD COLUMN sent_message_id char(26) NULL;
ALTER TABLE seller_turns ADD CONSTRAINT l1_05_sent_fields_pair CHECK (
  (status IN ('sent','edited')) = (sent_by_user_id IS NOT NULL AND sent_at IS NOT NULL AND sent_message_id IS NOT NULL)
);
ALTER TABLE seller_turns ADD CONSTRAINT l1_05_sent_message_fk
  FOREIGN KEY (sent_message_id, conversation_id, brand_id, organization_id)
  REFERENCES messages(id, conversation_id, brand_id, organization_id);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE seller_turns DROP CONSTRAINT l1_05_sent_message_fk;
ALTER TABLE seller_turns DROP CONSTRAINT l1_05_sent_fields_pair;
ALTER TABLE seller_turns DROP COLUMN sent_by_user_id, DROP COLUMN sent_at, DROP COLUMN sent_message_id;
ALTER TABLE seller_turns DROP CONSTRAINT e304_turn_status_check;
ALTER TABLE seller_turns ADD CONSTRAINT e304_turn_status_check
  CHECK (status IN ('processing','uncertain','completed','suggested','suppressed','failed'));
SQL);
    }
};
