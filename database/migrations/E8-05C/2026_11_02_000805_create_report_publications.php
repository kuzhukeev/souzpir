<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_publications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->ulid('report_id');
            $table->ulid('created_by');
            $table->char('token_hash', 64)->unique();
            $table->jsonb('public_payload');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['report_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('vision_reports')->restrictOnDelete();
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['id', 'brand_id', 'organization_id']);
            $table->index(['brand_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_publications');
    }
};
