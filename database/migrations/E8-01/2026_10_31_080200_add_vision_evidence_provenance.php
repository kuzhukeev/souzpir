<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('measurement_answers', function (Blueprint $table): void {
            $table->char('evidence_signature', 64)->nullable();
            $table->char('evidence_mac', 64)->nullable();
            $table->boolean('mentions_complete')->nullable();
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION vision_answer_identity_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.organization_id IS DISTINCT FROM OLD.organization_id
       OR NEW.brand_id IS DISTINCT FROM OLD.brand_id
       OR NEW.measurement_id IS DISTINCT FROM OLD.measurement_id
       OR NEW.question_n IS DISTINCT FROM OLD.question_n
       OR NEW.provider IS DISTINCT FROM OLD.provider
       OR NEW.run_no IS DISTINCT FROM OLD.run_no
       OR NEW.requested_model IS DISTINCT FROM OLD.requested_model THEN
        RAISE EXCEPTION 'Vision measurement slot identity cannot be modified';
    END IF;
    RETURN NEW;
END
$$;
SQL);
        DB::statement('CREATE TRIGGER vision_answer_identity_immutable BEFORE UPDATE ON measurement_answers FOR EACH ROW EXECUTE FUNCTION vision_answer_identity_immutable()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS vision_answer_identity_immutable ON measurement_answers');
        DB::statement('DROP FUNCTION IF EXISTS vision_answer_identity_immutable()');
        Schema::table('measurement_answers', function (Blueprint $table): void {
            $table->dropColumn(['evidence_signature', 'evidence_mac', 'mentions_complete']);
        });
    }
};
