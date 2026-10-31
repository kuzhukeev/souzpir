<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->foreignUlid('responsible')->nullable()->constrained('memberships')->restrictOnDelete();
            $table->date('due_date')->nullable();
            $table->text('comment')->nullable();
            $table->date('completed_at')->nullable();
            $table->string('evidence_url', 2048)->nullable();
            $table->string('status', 32)->default('open');
            $table->timestampsTz();
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $table->index(['organization_id', 'brand_id', 'status']);
        });

        Schema::create('audit_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('organization_id');
            $table->ulid('brand_id');
            $table->jsonb('result');
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $table->index(['organization_id', 'brand_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_runs');
        Schema::dropIfExists('actions');
    }
};
