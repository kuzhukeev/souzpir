<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shadow_comparisons', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->string('channel');
            $table->text('inbound_text');
            $table->text('baseline_response');
            $table->text('candidate_response');
            $table->string('verdict');
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $table->index(['organization_id', 'brand_id', 'verdict']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shadow_comparisons');
    }
};
