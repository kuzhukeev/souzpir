<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_achievements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->ulid('report_id');
            $table->string('code', 64);
            $table->timestampsTz();
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $table->foreign(['report_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('vision_reports')->restrictOnDelete();
            $table->unique(['brand_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_achievements');
    }
};
