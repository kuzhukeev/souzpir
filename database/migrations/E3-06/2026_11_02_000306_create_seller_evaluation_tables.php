<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_personas', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->ulid('brand_id');
            $table->string('key', 80);
            $table->string('name');
            $table->string('type', 32);
            $table->jsonb('payload');
            $table->unsignedSmallInteger('max_turns');
            $table->timestampsTz();
            $table->unique(['organization_id', 'brand_id', 'key']);
            $table->unique(['id', 'organization_id', 'brand_id']);
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
        });
        Schema::create('seller_sim_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->ulid('brand_id');
            $table->ulid('persona_id');
            $table->string('status', 20);
            $table->unsignedSmallInteger('turn_count');
            $table->jsonb('turns');
            $table->jsonb('metrics')->default('{}');
            $table->timestampsTz();
            $table->unique(['id', 'organization_id', 'brand_id']);
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $table->foreign(['persona_id', 'organization_id', 'brand_id'])->references(['id', 'organization_id', 'brand_id'])->on('seller_personas')->restrictOnDelete();
        });
        Schema::create('seller_evaluations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->ulid('brand_id');
            $table->ulid('sim_run_id')->nullable();
            $table->ulid('conversation_id')->nullable();
            $table->jsonb('scores');
            $table->jsonb('issues')->default('[]');
            $table->decimal('average', 3, 2);
            $table->text('comment')->nullable();
            $table->timestampsTz();
            $table->unique(['sim_run_id']);
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $table->foreign(['sim_run_id', 'organization_id', 'brand_id'])->references(['id', 'organization_id', 'brand_id'])->on('seller_sim_runs')->restrictOnDelete();
            $table->foreign(['conversation_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('conversations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_evaluations');
        Schema::dropIfExists('seller_sim_runs');
        Schema::dropIfExists('seller_personas');
    }
};
