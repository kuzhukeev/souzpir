<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_org_budget_overrides', function (Blueprint $t): void {
            $t->ulid('organization_id')->primary();
            $t->decimal('monthly_usd', 14, 6);
            $t->timestampsTz();
        });
        Schema::create('ai_budget_threshold_notices', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('scope', 20);
            $t->string('scope_key', 80);
            $t->date('period');
            $t->timestampTz('notified_at');
            $t->timestampsTz();
            $t->unique(['scope', 'scope_key', 'period']);
        });
        // Tracks only live (non-fake driver) reservations, separately from the
        // legacy per-row ai_budgets.reserved_micros, so existing fake-driver
        // tests pinned to that column stay unaffected.
        Schema::create('ai_live_reservations', function (Blueprint $t): void {
            $t->ulid('ai_call_id')->primary();
            $t->ulid('organization_id');
            $t->bigInteger('reserved_micros');
            $t->bigInteger('spent_micros')->nullable();
            $t->boolean('released')->default(false);
            $t->timestampsTz();
            $t->foreign('ai_call_id')->references('id')->on('ai_calls')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE ai_org_budget_overrides ADD CONSTRAINT ai_org_budget_override_positive CHECK (monthly_usd >= 0)');
        DB::statement("ALTER TABLE ai_budget_threshold_notices ADD CONSTRAINT ai_budget_threshold_scope CHECK (scope IN ('global_daily','global_monthly','organization'))");
        DB::statement('ALTER TABLE ai_live_reservations ADD CONSTRAINT ai_live_reservation_values CHECK (reserved_micros >= 0 AND (spent_micros IS NULL OR spent_micros >= 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_live_reservations');
        Schema::dropIfExists('ai_budget_threshold_notices');
        Schema::dropIfExists('ai_org_budget_overrides');
    }
};
