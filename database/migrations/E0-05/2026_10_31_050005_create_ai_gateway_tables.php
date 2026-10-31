<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $t): void {
            $t->string('code')->primary();
            $t->string('caption');
            $t->string('status');
            $t->timestampsTz();
        });
        Schema::create('ai_budgets', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('organization_id');
            $t->ulid('brand_id');
            $t->string('family', 20);
            $t->date('period');
            $t->decimal('limit_units', 28, 4)->nullable();
            $t->decimal('used_units', 28, 4)->nullable();
            $t->bigInteger('limit_micros')->nullable();
            $t->bigInteger('spent_micros')->default(0);
            $t->bigInteger('reserved_micros')->default(0);
            $t->unsignedInteger('unresolved_count')->default(0);
            $t->boolean('reservation_breached')->default(false);
            $t->timestampTz('threshold_notified_at')->nullable();
            $t->timestampsTz();
            $t->unique(['organization_id', 'brand_id', 'family', 'period']);
            $t->unique(['id', 'organization_id', 'brand_id']);
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
        });
        Schema::create('ai_calls', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('organization_id');
            $t->ulid('brand_id');
            $t->ulid('budget_id');
            $t->string('idempotency_key_hash', 64);
            $t->string('request_hash', 64);
            $t->string('family', 20);
            $t->string('purpose', 80);
            $t->string('provider', 80);
            $t->string('requested_model', 160);
            $t->string('model', 160);
            $t->string('object_type', 80);
            $t->string('object_id', 80);
            $t->jsonb('data_classes');
            $t->unsignedInteger('attempt');
            $t->ulid('parent_call_id')->nullable();
            $t->string('status', 32);
            $t->bigInteger('reserved_micros')->default(0);
            $t->bigInteger('cost_micros')->nullable();
            $t->boolean('reserve_exceeded')->default(false);
            $t->unsignedInteger('prompt_tokens')->nullable();
            $t->unsignedInteger('completion_tokens')->nullable();
            $t->unsignedInteger('latency_ms')->nullable();
            $t->string('error_code', 80)->nullable();
            $t->string('reconciliation_evidence_hash', 64)->nullable();
            $t->text('result_encrypted')->nullable();
            $t->timestampsTz();
            $t->unique(['organization_id', 'brand_id', 'idempotency_key_hash']);
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $t->unique(['id', 'organization_id', 'brand_id']);
            $t->foreign(['budget_id', 'organization_id', 'brand_id'])->references(['id', 'organization_id', 'brand_id'])->on('ai_budgets')->restrictOnDelete();
        });
        Schema::table('ai_calls', function (Blueprint $t): void {
            $t->foreign(['parent_call_id', 'organization_id', 'brand_id'])->references(['id', 'organization_id', 'brand_id'])->on('ai_calls')->restrictOnDelete();
        });
        Schema::create('object_costs', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('organization_id');
            $t->ulid('brand_id');
            $t->string('object_type', 80);
            $t->string('object_id', 80);
            $t->bigInteger('cost_micros')->default(0);
            $t->unsignedBigInteger('prompt_tokens')->default(0);
            $t->unsignedBigInteger('completion_tokens')->default(0);
            $t->timestampsTz();
            $t->unique(['organization_id', 'brand_id', 'object_type', 'object_id']);
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
        });
        Schema::create('prompt_sets', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->nullable();
            $t->ulid('brand_id')->nullable();
            $t->string('name');
            $t->string('version');
            $t->text('body');
            $t->string('status');
            $t->string('checksum', 64);
            $t->timestampsTz();
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX prompt_platform_version_unique ON prompt_sets (name, version) WHERE organization_id IS NULL AND brand_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX prompt_brand_version_unique ON prompt_sets (organization_id, brand_id, name, version) WHERE brand_id IS NOT NULL');
        DB::statement("ALTER TABLE ai_budgets ADD CONSTRAINT ai_budget_values CHECK ((limit_units IS NULL OR limit_units >= 0) AND (used_units IS NULL OR used_units >= 0) AND (limit_micros IS NULL OR limit_micros >= 0) AND spent_micros >= 0 AND reserved_micros >= 0 AND unresolved_count >= 0 AND family IN ('seller','vision'))");
        DB::statement("ALTER TABLE ai_calls ADD CONSTRAINT ai_call_values CHECK (status IN ('reserved','completed','failed','uncertain') AND reserved_micros >= 0 AND (cost_micros IS NULL OR cost_micros >= 0) AND attempt >= 1)");
        DB::statement("ALTER TABLE ai_providers ADD CONSTRAINT ai_provider_status CHECK (status IN ('active','planned'))");
        DB::statement('ALTER TABLE prompt_sets ADD CONSTRAINT ai_prompt_owner CHECK ((organization_id IS NULL) = (brand_id IS NULL))');
    }

    public function down(): void
    {
        foreach (['prompt_sets', 'object_costs', 'ai_calls', 'ai_budgets', 'ai_providers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
