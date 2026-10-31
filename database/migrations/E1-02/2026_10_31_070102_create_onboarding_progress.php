<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_progress', function (Blueprint $table): void {
            $table->foreignUlid('organization_id')->primary()->constrained('organizations')->restrictOnDelete();
            $table->ulid('brand_id')->nullable();
            $table->jsonb('draft');
            $table->string('logo_path')->nullable();
            $table->unsignedSmallInteger('step')->default(1);
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE onboarding_progress ADD CONSTRAINT e102_step_check CHECK (step BETWEEN 1 AND 4), ADD CONSTRAINT e102_completion_check CHECK ((brand_id IS NULL) = (completed_at IS NULL)), ADD CONSTRAINT e102_draft_object_check CHECK (jsonb_typeof(draft) = 'object')");
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_progress');
    }
};
