<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vision_personal_report_access', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->ulid('publication_id');
            $table->string('name', 120);
            $table->string('email', 254);
            $table->timestampsTz();
            $table->foreign(['publication_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('report_publications')->restrictOnDelete();
            $table->index(['organization_id', 'brand_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vision_personal_report_access');
    }
};
