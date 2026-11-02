<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snapshot_confirmations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->ulid('measurement_id');
            $table->foreignUlid('confirmed_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['id', 'brand_id', 'organization_id']);
            $table->unique(['measurement_id', 'brand_id', 'organization_id']);
            $table->foreign(['measurement_id', 'brand_id', 'organization_id'], 'snapshot_confirmations_measurement_fk')
                ->references(['id', 'brand_id', 'organization_id'])->on('measurements')->restrictOnDelete();
        });
        Schema::create('answer_facts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->ulid('snapshot_confirmation_id');
            $table->ulid('measurement_answer_id');
            $table->text('answer_text')->nullable();
            $table->jsonb('fact')->nullable();
            $table->timestampsTz();
            $table->unique(['snapshot_confirmation_id', 'measurement_answer_id'], 'answer_facts_confirmation_answer_unique');
            $table->foreign(['snapshot_confirmation_id', 'brand_id', 'organization_id'], 'answer_facts_confirmation_fk')
                ->references(['id', 'brand_id', 'organization_id'])->on('snapshot_confirmations')->restrictOnDelete();
            $table->foreign(['measurement_answer_id', 'brand_id', 'organization_id'], 'answer_facts_answer_fk')
                ->references(['id', 'brand_id', 'organization_id'])->on('measurement_answers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('answer_facts');
        Schema::dropIfExists('snapshot_confirmations');
    }
};
