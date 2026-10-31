<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vision_question_manual_batches', function (Blueprint $table): void {
            $table->ulid('batch_id')->primary();
            $table->ulid('profile_id');
            $table->ulid('quiz_id');
            $table->ulid('brand_id');
            $table->ulid('organization_id');
            $table->foreign(['batch_id', 'profile_id', 'quiz_id', 'brand_id', 'organization_id'], 'vision_manual_batch_tenant_fk')->references(['id', 'profile_id', 'quiz_id', 'brand_id', 'organization_id'])->on('question_generation_batches')->restrictOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->string('origin', 20)->default('manual');
            $table->ulid('source_set_id')->nullable();
            $table->foreign(['source_set_id', 'brand_id', 'organization_id'], 'vision_manual_source_set_fk')->references(['id', 'brand_id', 'organization_id'])->on('question_sets')->restrictOnDelete();
            $table->timestampTz('created_at');

        });
        DB::statement("ALTER TABLE vision_question_manual_batches ADD CONSTRAINT vision_manual_origin_check CHECK ((origin IN ('manual','questionnaire') AND source_set_id IS NULL) OR (origin = 'active_set' AND source_set_id IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX vision_manual_batch_actor_profile_unique ON vision_question_manual_batches (organization_id, brand_id, profile_id, created_by, origin) WHERE origin IN ('manual','questionnaire')");
        DB::statement("CREATE UNIQUE INDEX vision_copy_batch_actor_set_unique ON vision_question_manual_batches (organization_id, brand_id, profile_id, created_by, source_set_id) WHERE origin = 'active_set'");
        Schema::create('vision_question_copy_entries', function (Blueprint $table): void {
            $table->ulid('candidate_id')->primary();
            $table->ulid('brand_id');
            $table->ulid('organization_id');
            $table->foreign(['candidate_id', 'brand_id', 'organization_id'], 'vision_copy_candidate_fk')->references(['id', 'brand_id', 'organization_id'])->on('question_candidates')->cascadeOnDelete();
            $table->ulid('source_set_id');
            $table->foreign(['source_set_id', 'brand_id', 'organization_id'], 'vision_copy_source_fk')->references(['id', 'brand_id', 'organization_id'])->on('question_sets')->restrictOnDelete();
            $table->string('source_question_id', 100);
        });
        Schema::create('vision_question_approvals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->ulid('question_set_id');
            $table->foreign(['question_set_id', 'brand_id', 'organization_id'], 'vision_approval_set_tenant_fk')->references(['id', 'brand_id', 'organization_id'])->on('question_sets')->restrictOnDelete();
            $table->foreignUlid('approved_by')->constrained('users')->restrictOnDelete();
            $table->jsonb('providers');
            $table->string('frequency', 30)->nullable();
            $table->boolean('active')->default(true);
            $table->timestampTz('created_at');
            $table->unique(['question_set_id', 'brand_id', 'organization_id']);
        });
        DB::statement('CREATE UNIQUE INDEX vision_approval_one_active ON vision_question_approvals (organization_id, brand_id) WHERE active');
        DB::statement("ALTER TABLE vision_question_approvals ADD CONSTRAINT vision_approval_providers_object CHECK (jsonb_typeof(providers) = 'object')");
    }

    public function down(): void
    {
        Schema::dropIfExists('vision_question_approvals');
        Schema::dropIfExists('vision_question_copy_entries');
        Schema::dropIfExists('vision_question_manual_batches');
    }
};
