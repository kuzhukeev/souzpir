<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vision_competitor_aliases', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('organization_id');
            $t->ulid('brand_id');
            $t->string('alias');
            $t->string('canonical');
            $t->timestampsTz();
            $t->unique(['brand_id', 'alias']);
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
        });
        Schema::create('vision_competitor_tracking', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('organization_id');
            $t->ulid('brand_id');
            $t->string('name');
            $t->timestampsTz();
            $t->unique(['brand_id', 'name']);
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
        });
        Schema::create('vision_source_presence', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('organization_id');
            $t->ulid('brand_id');
            $t->string('domain');
            $t->string('presence', 16);
            $t->timestampsTz();
            $t->unique(['brand_id', 'domain']);
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vision_source_presence');
        Schema::dropIfExists('vision_competitor_tracking');
        Schema::dropIfExists('vision_competitor_aliases');
    }
};
