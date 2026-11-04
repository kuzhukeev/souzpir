<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vision_action_recommendation_links', function (Blueprint $table): void {
            $table->ulid('action_id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->ulid('report_id');
            $table->unsignedInteger('recommendation_index');
            $table->jsonb('metadata');
            $table->timestampsTz();
            $table->foreign('action_id')->references('id')->on('actions')->cascadeOnDelete();
            $table->index(['organization_id', 'brand_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vision_action_recommendation_links');
    }
};
