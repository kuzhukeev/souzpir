<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visibility_quizzes', function (Blueprint $table): void {
            $this->tenant($table);
            $table->unsignedInteger('version');
            $table->unsignedInteger('revision')->default(1);
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('current_step')->default(1);
            $table->jsonb('answers');
            $table->foreignUlid('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampsTz();
            $table->unique(['brand_id', 'version']);
        });
        DB::statement("CREATE UNIQUE INDEX vision_quiz_one_draft_per_brand ON visibility_quizzes (organization_id, brand_id) WHERE status = 'draft'");
        DB::statement("CREATE UNIQUE INDEX vision_quiz_one_submitted_per_brand ON visibility_quizzes (organization_id, brand_id) WHERE status = 'submitted'");
        DB::statement("ALTER TABLE visibility_quizzes ADD CONSTRAINT vision_quiz_valid CHECK (version > 0 AND revision > 0 AND current_step BETWEEN 1 AND 8 AND jsonb_typeof(answers) = 'object' AND octet_length(answers::text) <= 262144 AND ((status = 'draft' AND submitted_at IS NULL AND submitted_by IS NULL) OR (status IN ('submitted','superseded') AND submitted_at IS NOT NULL AND submitted_by IS NOT NULL)))");
        Schema::create('brand_profiles', function (Blueprint $table): void {
            $this->tenant($table);
            $table->ulid('quiz_id');
            $table->foreign(['quiz_id', 'brand_id', 'organization_id'], 'vision_profile_quiz_fk')->references(['id', 'brand_id', 'organization_id'])->on('visibility_quizzes')->restrictOnDelete();
            $table->ulid('previous_profile_id')->nullable();
            $table->foreign(['previous_profile_id', 'brand_id', 'organization_id'], 'vision_profile_previous_fk')->references(['id', 'brand_id', 'organization_id'])->on('brand_profiles')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('source', 16);
            $table->jsonb('answers');
            $table->char('checksum', 64);
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->unique(['brand_id', 'version']);
            $table->unique(['id', 'quiz_id', 'brand_id', 'organization_id'], 'vision_profile_version_tenant_unique');
        });
        DB::statement("CREATE UNIQUE INDEX vision_profile_once_per_quiz ON brand_profiles (quiz_id) WHERE source = 'submission'");
        DB::statement("ALTER TABLE brand_profiles ADD CONSTRAINT vision_profile_valid CHECK (version > 0 AND source IN ('submission','edit') AND jsonb_typeof(answers) = 'object' AND octet_length(answers::text) <= 262144 AND checksum ~ '^[0-9a-f]{64}$')");
        Schema::create('question_generation_batches', function (Blueprint $table): void {
            $this->tenant($table);
            $table->ulid('quiz_id');
            $table->ulid('profile_id');
            $table->foreign(['profile_id', 'quiz_id', 'brand_id', 'organization_id'], 'vision_batch_profile_fk')->references(['id', 'quiz_id', 'brand_id', 'organization_id'])->on('brand_profiles')->restrictOnDelete();
            $table->string('status', 20)->default('blocked');
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['id', 'profile_id', 'quiz_id', 'brand_id', 'organization_id'], 'vision_batch_profile_tenant_unique');
        });
        DB::statement("ALTER TABLE question_generation_batches ADD CONSTRAINT vision_batch_status_check CHECK (status IN ('blocked','pending','running','completed','failed'))");
        Schema::create('question_candidates', function (Blueprint $table): void {
            $this->tenant($table);
            $table->ulid('quiz_id');
            $table->ulid('profile_id');
            $table->ulid('batch_id');
            $table->foreign(['batch_id', 'profile_id', 'quiz_id', 'brand_id', 'organization_id'], 'vision_candidate_batch_fk')->references(['id', 'profile_id', 'quiz_id', 'brand_id', 'organization_id'])->on('question_generation_batches')->restrictOnDelete();
            $table->text('text');
            $table->string('intent', 20);
            $table->string('status', 20)->default('proposed');
            $table->boolean('selected')->default(false);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE question_candidates ADD CONSTRAINT vision_candidate_valid CHECK (char_length(text) BETWEEN 1 AND 4000 AND intent IN ('choose','price','terms','trust','after') AND status IN ('proposed','active','disabled'))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION vision_profile_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Vision profile versions are immutable'; END
$$;
SQL);
        DB::statement('CREATE TRIGGER vision_profile_immutable BEFORE UPDATE OR DELETE ON brand_profiles FOR EACH ROW EXECUTE FUNCTION vision_profile_immutable()');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION vision_submitted_quiz_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    IF OLD.status <> 'draft' THEN RAISE EXCEPTION 'Submitted quiz is immutable'; END IF;
    RETURN OLD;
  END IF;
  IF NEW.id IS DISTINCT FROM OLD.id OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
     OR NEW.brand_id IS DISTINCT FROM OLD.brand_id OR NEW.version IS DISTINCT FROM OLD.version
     OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
    RAISE EXCEPTION 'Quiz identity is immutable';
  END IF;
  IF OLD.status = 'draft' AND NEW.status NOT IN ('draft', 'submitted') THEN
    RAISE EXCEPTION 'Invalid quiz transition';
  END IF;
  IF OLD.status <> 'draft' AND (NEW.revision IS DISTINCT FROM OLD.revision
     OR NEW.current_step IS DISTINCT FROM OLD.current_step OR NEW.answers IS DISTINCT FROM OLD.answers
     OR NEW.submitted_by IS DISTINCT FROM OLD.submitted_by OR NEW.submitted_at IS DISTINCT FROM OLD.submitted_at
     OR NOT (NEW.status = OLD.status OR (OLD.status = 'submitted' AND NEW.status = 'superseded'))) THEN
    RAISE EXCEPTION 'Submitted quiz content is immutable';
  END IF;
  RETURN NEW;
END
$$;
SQL);
        DB::statement('CREATE TRIGGER vision_quiz_immutable BEFORE UPDATE OR DELETE ON visibility_quizzes FOR EACH ROW EXECUTE FUNCTION vision_submitted_quiz_immutable()');
    }

    private function tenant(Blueprint $table): void
    {
        $table->ulid('id')->primary();
        $table->ulid('organization_id');
        $table->ulid('brand_id');
        $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
        $table->unique(['id', 'brand_id', 'organization_id']);
    }

    public function down(): void
    {
        foreach (['question_candidates', 'question_generation_batches', 'brand_profiles', 'visibility_quizzes'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS vision_profile_immutable()');
        DB::statement('DROP FUNCTION IF EXISTS vision_submitted_quiz_immutable()');
    }
};
