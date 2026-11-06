<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_knowledge_import_documents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('organization_id', 26);
            $table->char('brand_id', 26);
            $table->string('name');
            $table->string('type', 8);
            $table->unsignedInteger('pages')->nullable();
            $table->string('status', 16);
            $table->timestampsTz();
            $table->index(['organization_id', 'brand_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_knowledge_import_documents');
    }
};
