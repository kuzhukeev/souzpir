<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('is_public')->default(false);
            $table->timestampsTz();
        });
        Schema::create('plan_limits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('key');
            $table->decimal('value', 20, 4)->nullable();
            $table->string('unit')->default('');
            $table->timestampsTz();
            $table->unique(['plan_id', 'key']);
        });
        Schema::create('organization_plan', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUlid('plan_id')->constrained('plans')->restrictOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'starts_at']);
        });
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Plans require PostgreSQL to enforce non-overlapping assignments.');
        }
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement('ALTER TABLE plan_limits ADD CONSTRAINT plan_limits_nonnegative CHECK (value IS NULL OR value >= 0)');
        DB::statement('ALTER TABLE organization_plan ADD CONSTRAINT organization_plan_interval CHECK (ends_at IS NULL OR ends_at > starts_at)');
        DB::statement("ALTER TABLE organization_plan ADD CONSTRAINT organization_plan_no_overlap EXCLUDE USING gist (organization_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&)");
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_plan');
        Schema::dropIfExists('plan_limits');
        Schema::dropIfExists('plans');
    }
};
