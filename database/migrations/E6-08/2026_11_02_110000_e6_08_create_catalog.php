<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_imports', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('organization_id');
            $t->ulid('brand_id');
            $t->string('filename', 200);
            $t->string('status', 20);
            $t->unsignedInteger('item_count')->default(0);
            $t->text('error')->nullable();
            $t->ulid('created_by');
            $t->timestampsTz();
            $t->unique(['id', 'brand_id', 'organization_id']);
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
            $t->foreign(['organization_id', 'created_by'])->references(['organization_id', 'user_id'])->on('memberships')->restrictOnDelete();
        });
        Schema::create('catalog_items', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('organization_id');
            $t->ulid('brand_id');
            $t->ulid('import_id');
            $t->string('sku')->nullable();
            $t->string('name', 500);
            $t->string('category')->nullable();
            $t->decimal('price', 14, 2)->nullable();
            $t->decimal('wholesale_price', 14, 2)->nullable();
            $t->decimal('stock', 14, 3)->nullable();
            $t->timestampsTz();
            $t->foreign(['import_id', 'brand_id', 'organization_id'])->references(['id', 'brand_id', 'organization_id'])->on('catalog_imports')->restrictOnDelete();
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
        });
        Schema::create('catalog_settings', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('organization_id');
            $t->ulid('brand_id');
            $t->decimal('low_stock', 14, 3)->default(0);
            $t->string('price_file_path')->nullable();
            $t->string('price_file_name')->nullable();
            $t->timestampsTz();
            $t->unique(['organization_id', 'brand_id']);
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_settings');
        Schema::dropIfExists('catalog_items');
        Schema::dropIfExists('catalog_imports');
    }
};
