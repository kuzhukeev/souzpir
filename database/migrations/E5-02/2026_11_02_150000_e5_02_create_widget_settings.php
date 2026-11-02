<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widget_settings', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('organization_id');
            $t->ulid('brand_id');
            $t->string('public_key', 64)->unique();
            $t->boolean('enabled')->default(false);
            $t->jsonb('origins');
            $t->jsonb('appearance');
            $t->jsonb('behavior');
            $t->timestampTz('last_seen_at')->nullable();
            $t->timestampsTz();
            $t->unique(['organization_id', 'brand_id']);
            $t->foreign(['brand_id', 'organization_id'])->references(['id', 'organization_id'])->on('brands')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_settings');
    }
};
