<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vision_clarification_decisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->ulid('snapshot_confirmation_id');
            $table->ulid('answer_fact_id');
            $table->string('item_key', 64);
            $table->string('kind', 20);
            $table->string('decision', 20);
            $table->text('correction')->nullable();
            $table->string('profile_url', 1000)->nullable();
            $table->boolean('add_alias')->default(false);
            $table->foreignUlid('decided_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['snapshot_confirmation_id', 'item_key'], 'vision_clarification_item_unique');
            $table->foreign(['snapshot_confirmation_id', 'brand_id', 'organization_id'], 'vision_clarification_confirmation_fk')
                ->references(['id', 'brand_id', 'organization_id'])->on('snapshot_confirmations')->restrictOnDelete();
            $table->foreign('answer_fact_id')->references('id')->on('answer_facts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vision_clarification_decisions');
    }
};
