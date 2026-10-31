<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_sets', function (Blueprint $t): void {
            $this->tenant($t);
            $t->unsignedInteger('version');
            $t->jsonb('questions');
            $t->unsignedInteger('size');
            $t->char('checksum', 64);
            $t->timestampsTz();
            $t->unique(['brand_id', 'version']);
        });
        Schema::create('measurements', function (Blueprint $t): void {
            $this->tenant($t);
            $t->ulid('question_set_id');
            $t->foreign(['question_set_id', 'brand_id', 'organization_id'], 'measurements_question_set_tenant_fk')
                ->references(['id', 'brand_id', 'organization_id'])->on('question_sets')->restrictOnDelete();
            $t->string('status', 24)->default('pending');
            $t->jsonb('configuration');
            $t->jsonb('profile_snapshot')->nullable();
            $t->char('profile_checksum', 64)->nullable();
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('finished_at')->nullable();
            $t->timestampsTz();
        });
        Schema::create('measurement_answers', function (Blueprint $t): void {
            $this->tenant($t);
            $t->ulid('measurement_id');
            $t->foreign(['measurement_id', 'brand_id', 'organization_id'], 'measurement_answers_measurement_tenant_fk')
                ->references(['id', 'brand_id', 'organization_id'])->on('measurements')->restrictOnDelete();
            $t->unsignedInteger('question_n');
            $t->string('provider', 100);
            $t->string('requested_model', 200);
            $t->string('model', 200)->nullable();
            $t->unsignedInteger('run_no');
            $t->string('status', 24)->default('pending');
            $t->text('answer_text')->nullable();
            $t->jsonb('fact')->nullable();
            $t->jsonb('mentions')->nullable();
            $t->jsonb('sources')->nullable();
            $t->boolean('protocol_valid')->nullable();
            $t->string('error_code', 80)->nullable();
            $t->ulid('ai_call_id')->nullable();
            $t->foreign(['ai_call_id', 'brand_id', 'organization_id'], 'measurement_answers_call_tenant_fk')
                ->references(['id', 'brand_id', 'organization_id'])->on('ai_calls')->restrictOnDelete();
            $t->timestampsTz();
            $t->unique(['measurement_id', 'question_n', 'provider', 'run_no'], 'measurement_slot_unique');
        });
        Schema::create('vision_reports', function (Blueprint $t): void {
            $this->tenant($t);
            $t->ulid('measurement_id');
            $t->foreign(['measurement_id', 'brand_id', 'organization_id'], 'vision_reports_measurement_tenant_fk')
                ->references(['id', 'brand_id', 'organization_id'])->on('measurements')->restrictOnDelete();
            $t->unsignedInteger('revision');
            $t->jsonb('payload');
            $t->char('checksum', 64);
            $t->timestampsTz();
            $t->unique(['measurement_id', 'revision']);
        });
        Schema::create('monitoring_schedules', function (Blueprint $t): void {
            $this->tenant($t);
            $t->boolean('enabled')->default(false);
            $t->string('frequency', 24)->nullable();
            $t->unsignedSmallInteger('weekday')->nullable();
            $t->time('local_time')->nullable();
            $t->string('timezone', 100)->nullable();
            $t->jsonb('models')->nullable();
            $t->timestampTz('next_run_at')->nullable();
            $t->timestampsTz();
            $t->unique(['brand_id']);
        });
        DB::statement("ALTER TABLE question_sets ADD CONSTRAINT vision_set_size_check CHECK (size > 0 AND jsonb_typeof(questions) = 'array' AND size = jsonb_array_length(questions))");
        DB::statement('ALTER TABLE question_sets ADD CONSTRAINT vision_set_version_check CHECK (version > 0)');
        DB::statement('ALTER TABLE vision_reports ADD CONSTRAINT vision_report_revision_check CHECK (revision > 0)');
        DB::statement('ALTER TABLE measurement_answers ADD CONSTRAINT vision_answer_index_check CHECK (question_n > 0 AND run_no > 0)');
        DB::statement("ALTER TABLE measurements ADD CONSTRAINT vision_measurement_status_check CHECK (status IN ('pending','running','completed','partial','failed'))");
        DB::statement('ALTER TABLE measurements ADD CONSTRAINT vision_measurement_profile_pair CHECK ((profile_snapshot IS NULL) = (profile_checksum IS NULL))');
        DB::statement("ALTER TABLE measurement_answers ADD CONSTRAINT vision_answer_status_check CHECK (status IN ('pending','in_progress','ok','failed','uncertain'))");
        DB::statement('ALTER TABLE monitoring_schedules ADD CONSTRAINT vision_schedule_weekday_check CHECK (weekday IS NULL OR weekday BETWEEN 1 AND 7)');
        DB::unprepared(<<<'SQL'
CREATE FUNCTION vision_core_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Vision snapshots and question sets are immutable'; END
$$;
SQL);
        DB::unprepared(<<<'SQL'
CREATE FUNCTION vision_plan_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.question_set_id IS DISTINCT FROM OLD.question_set_id
       OR NEW.configuration IS DISTINCT FROM OLD.configuration
       OR NEW.profile_snapshot IS DISTINCT FROM OLD.profile_snapshot
       OR NEW.profile_checksum IS DISTINCT FROM OLD.profile_checksum THEN
        RAISE EXCEPTION 'Vision frozen plan cannot be modified';
    END IF;
    RETURN NEW;
END
$$;
SQL);
        DB::statement('CREATE TRIGGER vision_measurement_plan_immutable BEFORE UPDATE ON measurements FOR EACH ROW EXECUTE FUNCTION vision_plan_immutable()');
        DB::statement('CREATE TRIGGER vision_question_set_immutable BEFORE UPDATE OR DELETE ON question_sets FOR EACH ROW EXECUTE FUNCTION vision_core_immutable()');
        DB::statement('CREATE TRIGGER vision_report_immutable BEFORE UPDATE OR DELETE ON vision_reports FOR EACH ROW EXECUTE FUNCTION vision_core_immutable()');
    }

    private function tenant(Blueprint $t): void
    {
        $t->ulid('id')->primary();
        $t->ulid('organization_id');
        $t->ulid('brand_id');
        $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
        $t->unique(['id', 'brand_id', 'organization_id']);
    }

    public function down(): void
    {
        foreach (['monitoring_schedules', 'vision_reports', 'measurement_answers', 'measurements', 'question_sets'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS vision_core_immutable()');
        DB::statement('DROP FUNCTION IF EXISTS vision_plan_immutable()');
    }
};
