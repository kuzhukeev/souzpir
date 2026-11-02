<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_supervisor_reports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $table->ulid('brand_id');
            $table->date('report_date');
            $table->jsonb('scores');
            $table->jsonb('issues');
            $table->decimal('average', 3, 2);
            $table->text('comment');
            $table->timestampsTz();
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $table->index(['organization_id', 'brand_id', 'report_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_supervisor_reports');
    }
};
